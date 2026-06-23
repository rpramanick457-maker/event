<?php
/**
 * activity_handler.php — Nexus AJAX Controller for Activity Registration
 */
header('Content-Type: application/json');

require_once 'db_connect.php';
require_once 'security.php';

// Auth check
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized access. Please log in.']);
    exit();
}

$user_id = $_SESSION['user_id'];

// Read JSON input payload
$inputData = json_decode(file_get_contents('php://input'), true);
if (!$inputData) {
    echo json_encode(['success' => false, 'error' => 'Invalid request payload.']);
    exit();
}

$action = $inputData['action'] ?? '';

if ($action === 'get_activities') {
    $event_id = intval($inputData['event_id'] ?? 0);
    $registration_id = intval($inputData['registration_id'] ?? 0);
    
    if (!$event_id || !$registration_id) {
        echo json_encode(['success' => false, 'error' => 'Missing event or registration identifiers.']);
        exit();
    }
    
    // Verify user owns this registration
    $stmt_verify = $conn->prepare("SELECT id, status FROM registrations WHERE id = ? AND user_id = ?");
    $stmt_verify->execute([$registration_id, $user_id]);
    $reg = $stmt_verify->fetch(PDO::FETCH_ASSOC);
    
    if (!$reg) {
        echo json_encode(['success' => false, 'error' => 'Invalid registration.']);
        exit();
    }
    
    if ($reg['status'] !== 'approved') {
        echo json_encode(['success' => false, 'error' => 'Main event registration is not approved yet.']);
        exit();
    }
    
    try {
        // Fetch all activities for the event
        $stmt_act = $conn->prepare("SELECT * FROM activities WHERE event_id = ? ORDER BY title ASC");
        $stmt_act->execute([$event_id]);
        $activities = $stmt_act->fetchAll(PDO::FETCH_ASSOC);
        
        $output_activities = [];
        
        foreach ($activities as $act) {
            $act_id = $act['id'];
            
            // Check if user is registered for this activity
            $stmt_my_reg = $conn->prepare("SELECT * FROM activity_registrations WHERE activity_id = ? AND registration_id = ?");
            $stmt_my_reg->execute([$act_id, $registration_id]);
            $my_act_reg = $stmt_my_reg->fetch(PDO::FETCH_ASSOC);
            
            $is_registered = !empty($my_act_reg);
            $reg_details = null;
            
            if ($is_registered) {
                // If group/duet, fetch team members
                $team_members = [];
                if ($act['activity_type'] !== 'solo' && !empty($my_act_reg['team_name'])) {
                    $stmt_team = $conn->prepare("
                        SELECT ar.registration_id, ar.team_leader_reg_id, r.student_name, r.roll_no 
                        FROM activity_registrations ar
                        JOIN registrations r ON ar.registration_id = r.id
                        WHERE ar.activity_id = ? AND ar.team_name = ?
                    ");
                    $stmt_team->execute([$act_id, $my_act_reg['team_name']]);
                    $members_list = $stmt_team->fetchAll(PDO::FETCH_ASSOC);
                    
                    foreach ($members_list as $m) {
                        $dec_name = decryptData($m['student_name']);
                        $dec_roll = decryptData($m['roll_no']);
                        
                        $is_leader = ($m['registration_id'] === $m['team_leader_reg_id']);
                        
                        $team_members[] = [
                            'name' => $dec_name,
                            'roll_no' => $dec_roll,
                            'is_leader' => $is_leader
                        ];
                    }
                }
                
                $reg_details = [
                    'status' => $my_act_reg['status'],
                    'team_name' => $my_act_reg['team_name'] ?? '',
                    'is_leader' => ($my_act_reg['registration_id'] === $my_act_reg['team_leader_reg_id']),
                    'track_link' => $my_act_reg['track_link'] ?? '',
                    'team_members' => $team_members
                ];
            }
            
            $output_activities[] = [
                'id' => $act['id'],
                'title' => $act['title'],
                'activity_type' => $act['activity_type'],
                'description' => $act['description'],
                'max_teams' => $act['max_teams'],
                'is_registered' => $is_registered,
                'registration_details' => $reg_details
            ];
        }
        
        echo json_encode(['success' => true, 'activities' => $output_activities]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
    exit();
}

if ($action === 'register_activity') {
    $activity_id = intval($inputData['activity_id'] ?? 0);
    $registration_id = intval($inputData['registration_id'] ?? 0);
    $track_link = trim($inputData['track_link'] ?? '');
    $team_name = trim($inputData['team_name'] ?? '');
    $members = $inputData['members'] ?? []; // Array of {name, roll_no}
    
    if (!$activity_id || !$registration_id) {
        echo json_encode(['success' => false, 'error' => 'Missing activity or registration identifiers.']);
        exit();
    }
    
    // Verify user owns this registration and it is approved
    $stmt_verify = $conn->prepare("SELECT id, status, event_id FROM registrations WHERE id = ? AND user_id = ?");
    $stmt_verify->execute([$registration_id, $user_id]);
    $reg = $stmt_verify->fetch(PDO::FETCH_ASSOC);
    
    if (!$reg) {
        echo json_encode(['success' => false, 'error' => 'Invalid registration.']);
        exit();
    }
    
    if ($reg['status'] !== 'approved') {
        echo json_encode(['success' => false, 'error' => 'Your main registration for this event must be approved first.']);
        exit();
    }
    
    $event_id = $reg['event_id'];
    
    // Fetch activity details
    $stmt_act = $conn->prepare("SELECT * FROM activities WHERE id = ? AND event_id = ?");
    $stmt_act->execute([$activity_id, $event_id]);
    $activity = $stmt_act->fetch(PDO::FETCH_ASSOC);
    
    if (!$activity) {
        echo json_encode(['success' => false, 'error' => 'Activity not found for this event.']);
        exit();
    }
    
    // Check if the current user is already registered for this activity
    $stmt_check_my = $conn->prepare("SELECT id FROM activity_registrations WHERE activity_id = ? AND registration_id = ?");
    $stmt_check_my->execute([$activity_id, $registration_id]);
    if ($stmt_check_my->fetch()) {
        echo json_encode(['success' => false, 'error' => 'You are already registered for this activity.']);
        exit();
    }
    
    $activity_type = $activity['activity_type'];
    
    // Begin validation and collection of participant registration IDs
    $participants_to_register = []; // List of registration IDs
    
    if ($activity_type !== 'solo') {
        // Group/Duet requires a team name
        if (empty($team_name)) {
            echo json_encode(['success' => false, 'error' => 'Team Name is required for ' . ucfirst($activity_type) . ' activities.']);
            exit();
        }
        
        // Duet must have exactly 1 partner, Group must have at least 1 other member
        if ($activity_type === 'duet' && count($members) !== 1) {
            echo json_encode(['success' => false, 'error' => 'Duet activity requires exactly 1 partner details.']);
            exit();
        }
        if ($activity_type === 'group' && count($members) < 1) {
            echo json_encode(['success' => false, 'error' => 'Group activity requires at least 1 other team member.']);
            exit();
        }
        
        // Fetch all approved event registrations to check team members
        $stmt_approved_regs = $conn->prepare("SELECT id, student_name, roll_no FROM registrations WHERE event_id = ? AND status = 'approved'");
        $stmt_approved_regs->execute([$event_id]);
        $approved_regs = $stmt_approved_regs->fetchAll(PDO::FETCH_ASSOC);
        
        // Loop through inputted members and match with database
        foreach ($members as $index => $m) {
            $input_name = strtolower(trim($m['name'] ?? ''));
            $input_roll = strtolower(trim($m['roll_no'] ?? ''));
            
            if (empty($input_name) || empty($input_roll)) {
                echo json_encode(['success' => false, 'error' => "Member #" . ($index + 1) . " details are incomplete."]);
                exit();
            }
            
            $matched_reg_id = null;
            $matched_actual_name = '';
            
            foreach ($approved_regs as $ar) {
                $dec_name = strtolower(trim(decryptData($ar['student_name'])));
                $dec_roll = strtolower(trim(decryptData($ar['roll_no'])));
                
                if ($dec_name === $input_name && $dec_roll === $input_roll) {
                    $matched_reg_id = $ar['id'];
                    $matched_actual_name = decryptData($ar['student_name']);
                    break;
                }
            }
            
            if (!$matched_reg_id) {
                echo json_encode([
                    'success' => false, 
                    'error' => "Member '" . htmlspecialchars($m['name']) . "' with Roll '" . htmlspecialchars($m['roll_no']) . "' could not be verified. Please verify they are registered and approved for the event using exactly the same details."
                ]);
                exit();
            }
            
            // Check if they are trying to add themselves as a team member
            if ($matched_reg_id === $registration_id) {
                echo json_encode(['success' => false, 'error' => "You do not need to add yourself as a team member. You are automatically added as the team leader."]);
                exit();
            }
            
            // Check if this matched registration ID is already registered for this activity
            $stmt_check_other = $conn->prepare("SELECT id FROM activity_registrations WHERE activity_id = ? AND registration_id = ?");
            $stmt_check_other->execute([$activity_id, $matched_reg_id]);
            if ($stmt_check_other->fetch()) {
                echo json_encode(['success' => false, 'error' => "Member '" . htmlspecialchars($matched_actual_name) . "' is already registered for this activity in another team."]);
                exit();
            }
            
            // Check for duplicate inputs in the payload
            if (in_array($matched_reg_id, $participants_to_register)) {
                echo json_encode(['success' => false, 'error' => "Member '" . htmlspecialchars($matched_actual_name) . "' is listed multiple times."]);
                exit();
            }
            
            $participants_to_register[] = $matched_reg_id;
        }
    }
    
    // Everything is validated. Perform DB Insertion.
    try {
        $conn->beginTransaction();
        
        // 1. Insert for the Leader (current user)
        $stmt_insert_leader = $conn->prepare("
            INSERT INTO activity_registrations (activity_id, registration_id, team_name, team_leader_reg_id, track_link, status)
            VALUES (?, ?, ?, ?, ?, 'pending')
        ");
        $l_team_name = ($activity_type !== 'solo') ? $team_name : null;
        $stmt_insert_leader->execute([$activity_id, $registration_id, $l_team_name, $registration_id, $track_link]);
        
        // 2. Insert for all team members
        if ($activity_type !== 'solo') {
            $stmt_insert_member = $conn->prepare("
                INSERT INTO activity_registrations (activity_id, registration_id, team_name, team_leader_reg_id, track_link, status)
                VALUES (?, ?, ?, ?, ?, 'pending')
            ");
            foreach ($participants_to_register as $member_reg_id) {
                $stmt_insert_member->execute([$activity_id, $member_reg_id, $team_name, $registration_id, $track_link]);
            }
        }
        
        $conn->commit();
        
        // Log action in system logs
        $leader_name = $_SESSION['user_name'];
        $act_title = $activity['title'];
        $log_msg = "Student '$leader_name' registered for activity '$act_title'";
        if ($activity_type !== 'solo') {
            $log_msg .= " with Team Name '$team_name' (" . (count($participants_to_register) + 1) . " members total)";
        }
        $log_msg .= ".";
        logSystemMessage($conn, $log_msg, "info");
        
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $conn->rollBack();
        echo json_encode(['success' => false, 'error' => 'Failed to save registrations: ' . $e->getMessage()]);
    }
    exit();
}

echo json_encode(['success' => false, 'error' => 'Invalid action request.']);
exit();
