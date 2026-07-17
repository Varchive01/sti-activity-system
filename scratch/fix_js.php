<?php
$files = [
    'admin1/dashboard.php',
    'admin2/dashboard.php',
    'faculty/dashboard.php',
    'dean/dashboard.php'
];

$jsToInsert = <<<JS

      if (deleteAllBtn) {
        deleteAllBtn.addEventListener('click', () => {
          fetch(BASE_URL + '/api/notifications.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'delete_all' })
          }).then(r => r.json()).then(d => {
            if (!d.success) return;
            const list = document.getElementById('notifList');
            if (list) {
                list.innerHTML = `<div class="notif-empty">
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
              d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6 6 0 00-5-5.917V4a1 1 0 10-2 0v1.083A6 6 0 006 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
          </svg>
          <p>You're all caught up!<br><span style="font-size:.75rem;">No notifications yet.</span></p>
        </div>`;
            }
            document.getElementById('notifBadge')?.remove();
            if (markAllBtn) markAllBtn.style.display = 'none';
            deleteAllBtn.style.display = 'none';
            const hspan = document.getElementById('notifDropdown').querySelector('.notif-header h3 span');
            if(hspan) hspan.remove();
          });
        });
      }
JS;

foreach ($files as $file) {
    $content = file_get_contents(__DIR__ . '/../' . $file);
    
    // Check if it's already there
    if (strpos($content, "deleteAllBtn.addEventListener") !== false) {
        echo "Already in $file\\n";
        continue;
    }
    
    // Use regex to insert after the markAllBtn if block
    // Look for the end of markAllBtn block which ends with: panel.querySelector('.notif-header h3 span')?.remove(); }); }); }
    $content = preg_replace(
        '/(\}\);[\r\n\s]*\}\);[\r\n\s]*\})/s',
        "$1\n" . $jsToInsert,
        $content,
        1
    );

    file_put_contents(__DIR__ . '/../' . $file, $content);
}
echo "Done JS injection\\n";
