(function() {
    // Determine the theme immediately to avoid page flashing
    const savedTheme = localStorage.getItem('theme') || 'light';
    document.documentElement.setAttribute('data-theme', savedTheme);
})();

document.addEventListener('DOMContentLoaded', () => {
    const toggleBtns = document.querySelectorAll('.theme-toggle-btn');
    
    function updateToggleButtons(theme) {
        toggleBtns.forEach(btn => {
            if (theme === 'dark') {
                btn.innerHTML = '☀️'; // Sun icon to switch to light
                btn.title = 'Switch to Light Mode';
            } else {
                btn.innerHTML = '🌙'; // Moon icon to switch to dark
                btn.title = 'Switch to Dark Mode';
            }
        });
    }

    const currentTheme = document.documentElement.getAttribute('data-theme') || 'light';
    updateToggleButtons(currentTheme);

    toggleBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            const theme = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', theme);
            localStorage.setItem('theme', theme);
            updateToggleButtons(theme);
        });
    });

    // Inject background liquid blobs for premium liquid glass effect
    const b1 = document.createElement('div');
    b1.className = 'liquid-blob blob-1';
    const b2 = document.createElement('div');
    b2.className = 'liquid-blob blob-2';
    const b3 = document.createElement('div');
    b3.className = 'liquid-blob blob-3';
    
    document.body.appendChild(b1);
    document.body.appendChild(b2);
    document.body.appendChild(b3);

    // Responsive hamburger menu logic
    const navContainer = document.querySelector('.nav-container');
    const navLinks = document.querySelector('.nav-links');
    
    if (navContainer && navLinks) {
        // Create toggle button dynamically
        const toggleBtn = document.createElement('button');
        toggleBtn.className = 'nav-toggle';
        toggleBtn.setAttribute('aria-label', 'Toggle Navigation');
        toggleBtn.innerHTML = '<span></span><span></span><span></span>';
        
        // Insert toggle button before navLinks
        navContainer.insertBefore(toggleBtn, navLinks);
        
        // Toggle action
        toggleBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            toggleBtn.classList.toggle('active');
            navLinks.classList.toggle('active');
        });
        
        // Close menu when clicking outside
        document.addEventListener('click', (e) => {
            if (navLinks.classList.contains('active') && !navContainer.contains(e.target)) {
                toggleBtn.classList.remove('active');
                navLinks.classList.remove('active');
            }
        });
        
        // Close menu when clicking a link
        navLinks.querySelectorAll('a').forEach(link => {
            link.addEventListener('click', () => {
                toggleBtn.classList.remove('active');
                navLinks.classList.remove('active');
            });
        });
    }
});
