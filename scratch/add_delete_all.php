<?php
$files = [
    'admin1/dashboard.php',
    'admin2/dashboard.php',
    'faculty/dashboard.php',
    'dean/dashboard.php'
];

foreach ($files as $file) {
    $content = file_get_contents(__DIR__ . '/../' . $file);
    
    // 1. HTML: Add Delete all button
    $htmlToInsert = "\n        <?php if (!empty(\$notifications)): ?>\n          <button class=\"notif-mark-all\" id=\"deleteAllBtn\" style=\"color: #EF4444; margin-left: 8px;\">Delete all</button>\n        <?php endif; ?>";
    $content = preg_replace('/(id="markAllBtn"[^>]*>.*?<\/button>\s*<\?php endif; \?>)/s', "$1$htmlToInsert", $content);

    // 2. JS: Add const deleteAllBtn
    $content = str_replace(
        "const markAllBtn = document.getElementById('markAllBtn');", 
        "const markAllBtn = document.getElementById('markAllBtn');\n      const deleteAllBtn = document.getElementById('deleteAllBtn');", 
        $content
    );

    // 3. JS: Add event listener logic
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
            const hspan = panel.querySelector('.notif-header h3 span');
            if(hspan) hspan.remove();
          });
        });
      }
JS;

    // Use string position and substring to inject JS exactly after the markAllBtn listener block
    // Search for: panel.querySelector('.notif-header h3 span')?.remove();\n          });\n        });\n      }
    $searchJs = "panel.querySelector('.notif-header h3 span')?.remove();\n          });\n        });\n      }";
    $content = str_replace($searchJs, $searchJs . "\n" . $jsToInsert, $content);

    file_put_contents(__DIR__ . '/../' . $file, $content);
}
echo "Done\\n";
