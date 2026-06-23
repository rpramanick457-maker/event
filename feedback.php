<?php
require_once 'db_connect.php';
require_once 'otp_handler.php';
require_once 'security.php';

$error = '';
$success = '';

$token = isset($_GET['token']) ? trim($_GET['token']) : '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = isset($_POST['token']) ? trim($_POST['token']) : '';
}

$reg = null;

if (empty($token)) {
    $error = 'Invalid token. A feedback token is required to access this page.';
} else {
    try {
        // Fetch registration and event details
        $stmt = $conn->prepare("
            SELECT r.*, e.title as event_title, u.email as registered_email 
            FROM registrations r 
            JOIN events e ON r.event_id = e.id 
            JOIN users u ON r.user_id = u.id 
            WHERE r.qr_token = ?
        ");
        $stmt->execute([$token]);
        $reg = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$reg) {
            $error = 'Ticket not found. Please verify the feedback link.';
        } else {
            // Decrypt student name
            $reg['student_name'] = decryptData($reg['student_name']);

            if ($reg['status'] !== 'approved') {
                $error = 'Your registration for this event is not approved.';
            } elseif ($reg['qr_status'] !== 'deactivated') {
                $error = 'Attendance required. You can only submit feedback after checking out from the event.';
            } else {
                // Check if feedback already exists
                $stmt_check = $conn->prepare("SELECT id FROM feedbacks WHERE registration_id = ?");
                $stmt_check->execute([$reg['id']]);
                if ($stmt_check->fetch()) {
                    $success = 'Thank you! Your feedback for this event has already been submitted.';
                }
            }
        }
    } catch (PDOException $e) {
        $error = 'Database error: ' . $e->getMessage();
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error) && empty($success)) {
    $rating = isset($_POST['rating']) ? intval($_POST['rating']) : 0;
    $comment = isset($_POST['comment']) ? trim($_POST['comment']) : '';
    
    if ($rating < 1 || $rating > 5) {
        $error = 'Please select a rating between 1 and 5 stars.';
    } else {
        try {
            $insert = $conn->prepare("
                INSERT INTO feedbacks (registration_id, student_name, email, event_id, rating, comment) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $insert->execute([
                $reg['id'],
                $reg['student_name'],
                $reg['registered_email'],
                $reg['event_id'],
                $rating,
                $comment
            ]);
            
            // Log success message in system audit log
            logSystemMessage($conn, "Feedback submitted by student '{$reg['student_name']}' for event '{$reg['event_title']}' (Rating: {$rating}/5).", "success");
            
            $success = 'Thank you! Your feedback has been submitted successfully.';
        } catch (PDOException $e) {
            $error = 'Failed to submit feedback: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submit Event Feedback | Nexus</title>
    <link rel="stylesheet" href="css/style.css?v=1.6">
    <script src="js/theme.js?v=1.6"></script>
    <style>
        .feedback-wrapper {
            max-width: 550px;
            margin: 4rem auto;
            padding: 0 1.5rem;
        }
        
        .stars-container {
            display: flex;
            gap: 0.5rem;
            justify-content: center;
            margin: 1.5rem 0;
            flex-direction: row-reverse; /* For hover styling logic */
        }
        
        .star-radio {
            display: none;
        }
        
        .star-label {
            font-size: 2.5rem;
            color: var(--border-color);
            cursor: pointer;
            transition: var(--transition);
        }
        
        /* Interactive Star Hovering and Active States */
        .stars-container input:checked ~ label,
        .stars-container label:hover,
        .stars-container label:hover ~ label {
            color: #FFC107;
            text-shadow: 0 0 10px rgba(255, 193, 7, 0.4);
        }
        
        /* Make form elements clean and readonly */
        .form-control[readonly] {
            opacity: 0.8;
            cursor: not-allowed;
            background: rgba(255, 255, 255, 0.02);
            border-color: var(--border-color);
        }
    </style>
</head>
<body>
    <!-- Header/Nav -->
    <header>
        <div class="nav-container">
            <a href="index.php" class="logo-container">
                <img src="images/nexus_logo.png" alt="Nexus Logo" class="logo-img">
                <span class="logo-text">Nexus</span>
            </a>
            <div style="display: flex; align-items: center; gap: 1rem;">
                <button class="theme-toggle-btn" aria-label="Toggle Theme">🌙</button>
            </div>
        </div>
    </header>

    <div class="feedback-wrapper">
        <div class="glass-panel" style="padding: 2.5rem 2rem;">
            <h2 style="text-align: center; margin-bottom: 0.5rem; font-weight: 800; font-size: 1.85rem; color: var(--primary);">Event Feedback</h2>
            <p style="text-align: center; color: var(--text-secondary); margin-bottom: 2rem; font-size: 0.95rem;">Tell us about your experience to help us improve future campus events.</p>
            
            <?php if (!empty($error) && empty($reg)): ?>
                <div class="alert alert-danger" style="margin-bottom: 1.5rem;">
                    <span>⚠️</span> <?php echo htmlspecialchars($error); ?>
                </div>
                <div style="text-align: center; margin-top: 2rem;">
                    <a href="index.php" class="btn-card" style="display: inline-block; width: auto; padding: 0.6rem 1.5rem;">Go to Home</a>
                </div>
            <?php elseif (!empty($success)): ?>
                <div class="alert alert-success" style="margin-bottom: 1.5rem; text-align: center; display: block;">
                    <span style="font-size: 2.5rem; display: block; margin-bottom: 0.75rem;">🎉</span>
                    <h3 style="color: #34d399; margin-bottom: 0.5rem;">Submission Recorded</h3>
                    <p style="font-size: 0.9rem; line-height: 1.4; color: var(--text-secondary);"><?php echo htmlspecialchars($success); ?></p>
                </div>
                <div style="text-align: center; margin-top: 2rem;">
                    <a href="index.php" class="btn-card" style="display: inline-block; width: auto; padding: 0.6rem 1.5rem;">Back to Events</a>
                </div>
            <?php else: ?>
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger" style="margin-bottom: 1.5rem;">
                        <span>⚠️</span> <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>
                
                <form action="feedback.php" method="POST">
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                    
                    <div class="form-group">
                        <label class="form-label">Student Name</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($reg['student_name']); ?>" readonly>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Registered Email</label>
                        <input type="email" class="form-control" value="<?php echo htmlspecialchars($reg['registered_email']); ?>" readonly>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Event Name</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($reg['event_title']); ?>" readonly>
                    </div>
                    
                    <div class="form-group" style="text-align: center; margin: 2rem 0 1.5rem 0;">
                        <label class="form-label" style="text-align: center; display: block; margin-bottom: 0.5rem;">Your Rating</label>
                        <div class="stars-container">
                            <input type="radio" id="star5" name="rating" value="5" class="star-radio" checked>
                            <label for="star5" class="star-label">★</label>
                            
                            <input type="radio" id="star4" name="rating" value="4" class="star-radio">
                            <label for="star4" class="star-label">★</label>
                            
                            <input type="radio" id="star3" name="rating" value="3" class="star-radio">
                            <label for="star3" class="star-label">★</label>
                            
                            <input type="radio" id="star2" name="rating" value="2" class="star-radio">
                            <label for="star2" class="star-label">★</label>
                            
                            <input type="radio" id="star1" name="rating" value="1" class="star-radio">
                            <label for="star1" class="star-label">★</label>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="feedback-comment">Feedback / Comments</label>
                        <textarea id="feedback-comment" name="comment" class="form-control" rows="4" placeholder="How was the event? Share your thoughts, suggestions, or comments..." style="resize: vertical;"></textarea>
                    </div>
                    
                    <button type="submit" class="btn-card" style="width: 100%; padding: 0.9rem; font-size: 1rem; border-color: var(--primary); color: var(--text-primary); margin-top: 1rem;">
                        Submit Feedback
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
