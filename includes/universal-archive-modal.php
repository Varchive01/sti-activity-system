<?php
/**
 * Universal Archive Modal Component
 * Globally provides the modal dialog and JavaScript controller to archive
 * completed activities (by specific month or all completed) and restore them.
 */
?>
<!-- Universal Archive Modal Overlay -->
<div id="stiUniversalArchiveModal" style="display:none; position:fixed; inset:0; z-index:99999; background:rgba(15,23,42,0.65); backdrop-filter:blur(4px); align-items:center; justify-content:center; padding:16px;">
  <div style="background:var(--bg-card, #ffffff); color:var(--text-main, #0f172a); border-radius:14px; width:100%; max-width:520px; box-shadow:0 20px 25px -5px rgba(0,0,0,0.25), 0 10px 10px -5px rgba(0,0,0,0.15); border:1px solid var(--border, #e2e8f0); overflow:hidden; animation:stiModalFadeIn 0.2s ease-out;">
    
    <!-- Modal Header -->
    <div style="padding:18px 24px; border-bottom:1px solid var(--border, #e2e8f0); display:flex; align-items:center; justify-content:space-between; background:var(--bg-base, #f8fafc);">
      <div style="display:flex; align-items:center; gap:10px;">
        <span style="font-size:1.4rem;">📦</span>
        <div>
          <h3 style="margin:0; font-size:1.1rem; font-weight:700; color:var(--text-main, #0f172a);">Universal Activity Archive</h3>
          <p style="margin:2px 0 0; font-size:0.78rem; color:var(--text-muted, #64748b);">Archive completed activities to streamline active tables and reports.</p>
        </div>
      </div>
      <button type="button" onclick="closeUniversalArchiveModal()" style="background:none; border:none; font-size:1.3rem; line-height:1; color:var(--text-muted, #64748b); cursor:pointer; padding:4px 8px; border-radius:6px;" title="Close">&times;</button>
    </div>

    <!-- Modal Body -->
    <div style="padding:22px 24px;">
      <!-- Scope Selection -->
      <label style="display:block; font-size:0.85rem; font-weight:600; margin-bottom:10px; color:var(--text-main, #0f172a);">Select Archive Scope:</label>
      
      <div style="display:flex; flex-direction:column; gap:10px; margin-bottom:18px;">
        <!-- Option 1: By Month -->
        <label style="display:flex; align-items:flex-start; gap:10px; padding:12px 14px; border:1px solid var(--border, #cbd5e1); border-radius:8px; cursor:pointer; background:var(--bg-card, #ffffff); transition:border-color 0.15s ease;" id="scopeMonthLabel">
          <input type="radio" name="stiArchiveScope" value="month" checked onchange="onArchiveScopeChange()" style="margin-top:3px;">
          <div style="flex:1;">
            <div style="font-weight:600; font-size:0.88rem;">Archive by Specific Month</div>
            <div style="font-size:0.78rem; color:var(--text-muted, #64748b);">Archive activities completed within a selected month and year.</div>
            
            <!-- Month & Year Selectors -->
            <div id="stiArchiveMonthInputs" style="display:flex; gap:8px; margin-top:10px;">
              <select id="stiArchiveMonth" onchange="fetchArchivePreview()" class="form-control" style="font-size:0.85rem; padding:6px 10px; border-radius:6px; border:1px solid var(--border, #cbd5e1); background:var(--bg-base, #ffffff); color:var(--text-main, #0f172a); flex:2;">
                <?php
                $currentMonth = (int)date('n');
                for ($m = 1; $m <= 12; $m++):
                  $mName = date('F', mktime(0, 0, 0, $m, 1));
                ?>
                  <option value="<?= $m ?>" <?= ($m === $currentMonth) ? 'selected' : '' ?>><?= $mName ?></option>
                <?php endfor; ?>
              </select>

              <select id="stiArchiveYear" onchange="fetchArchivePreview()" class="form-control" style="font-size:0.85rem; padding:6px 10px; border-radius:6px; border:1px solid var(--border, #cbd5e1); background:var(--bg-base, #ffffff); color:var(--text-main, #0f172a); flex:1;">
                <?php
                $currentYear = (int)date('Y');
                for ($y = $currentYear + 1; $y >= $currentYear - 3; $y--):
                ?>
                  <option value="<?= $y ?>" <?= ($y === $currentYear) ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
              </select>
            </div>
          </div>
        </label>

        <!-- Option 2: All Completed -->
        <label style="display:flex; align-items:flex-start; gap:10px; padding:12px 14px; border:1px solid var(--border, #cbd5e1); border-radius:8px; cursor:pointer; background:var(--bg-card, #ffffff); transition:border-color 0.15s ease;" id="scopeAllLabel">
          <input type="radio" name="stiArchiveScope" value="all" onchange="onArchiveScopeChange()" style="margin-top:3px;">
          <div>
            <div style="font-weight:600; font-size:0.88rem;">Archive All Completed Activities</div>
            <div style="font-size:0.78rem; color:var(--text-muted, #64748b);">Archive all unarchived activities currently in Completed status.</div>
          </div>
        </label>
      </div>

      <!-- Preview Summary Box -->
      <div id="stiArchivePreviewBox" style="background:var(--bg-base, #f8fafc); border:1px solid var(--border, #e2e8f0); border-radius:8px; padding:14px; font-size:0.85rem;">
        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:6px;">
          <span style="font-weight:600; color:var(--text-main, #0f172a);">Preview Summary:</span>
          <span id="stiArchiveCountBadge" style="font-size:0.75rem; padding:3px 8px; border-radius:12px; background:var(--accent, #0284c7); color:#fff; font-weight:700;">Loading…</span>
        </div>
        <div id="stiArchivePreviewText" style="color:var(--text-muted, #64748b); font-size:0.82rem;">Checking matching completed activities…</div>
        <ul id="stiArchivePreviewList" style="margin:8px 0 0; padding-left:18px; font-size:0.8rem; color:var(--text-main, #0f172a); max-height:100px; overflow-y:auto; display:none;"></ul>
      </div>

      <div style="margin-top:14px; font-size:0.78rem; color:var(--text-muted, #64748b); display:flex; align-items:flex-start; gap:6px;">
        <span>ℹ️</span>
        <span>Archived activities are never deleted. You can view, search, and restore them anytime using the <strong>Archived</strong> filter.</span>
      </div>
    </div>

    <!-- Modal Footer -->
    <div style="padding:14px 24px; border-top:1px solid var(--border, #e2e8f0); background:var(--bg-base, #f8fafc); display:flex; align-items:center; justify-content:flex-end; gap:10px;">
      <button type="button" onclick="closeUniversalArchiveModal()" class="btn btn-outline btn-sm" style="padding:7px 14px; font-size:0.85rem;">Cancel</button>
      <button type="button" id="stiBtnExecuteArchive" onclick="submitUniversalArchive()" class="btn btn-primary btn-sm" style="padding:7px 16px; font-size:0.85rem; display:inline-flex; align-items:center; gap:6px;">
        <span>📦</span> <span id="stiBtnExecuteArchiveText">Archive Activities</span>
      </button>
    </div>

  </div>
</div>

<script>
(function() {
  var baseUrl = '<?= defined("BASE_URL") ? BASE_URL : "" ?>';

  window.openUniversalArchiveModal = function() {
    var modal = document.getElementById('stiUniversalArchiveModal');
    if (!modal) return;
    modal.style.display = 'flex';
    fetchArchivePreview();
  };

  window.closeUniversalArchiveModal = function() {
    var modal = document.getElementById('stiUniversalArchiveModal');
    if (modal) modal.style.display = 'none';
  };

  window.onArchiveScopeChange = function() {
    var radios = document.getElementsByName('stiArchiveScope');
    var isMonth = true;
    for (var i = 0; i < radios.length; i++) {
      if (radios[i].checked && radios[i].value === 'all') isMonth = false;
    }
    var inputs = document.getElementById('stiArchiveMonthInputs');
    if (inputs) {
      inputs.style.opacity = isMonth ? '1' : '0.4';
      inputs.style.pointerEvents = isMonth ? 'auto' : 'none';
    }
    fetchArchivePreview();
  };

  window.fetchArchivePreview = async function() {
    var badge = document.getElementById('stiArchiveCountBadge');
    var text  = document.getElementById('stiArchivePreviewText');
    var list  = document.getElementById('stiArchivePreviewList');
    var btn   = document.getElementById('stiBtnExecuteArchive');

    if (!badge || !text) return;
    badge.textContent = '…';
    badge.style.background = 'var(--text-muted, #64748b)';
    text.textContent = 'Scanning completed activities…';
    if (list) list.style.display = 'none';

    var radios = document.getElementsByName('stiArchiveScope');
    var scope = 'month';
    for (var i = 0; i < radios.length; i++) {
      if (radios[i].checked) scope = radios[i].value;
    }

    var month = document.getElementById('stiArchiveMonth') ? document.getElementById('stiArchiveMonth').value : 1;
    var year  = document.getElementById('stiArchiveYear') ? document.getElementById('stiArchiveYear').value : new Date().getFullYear();

    try {
      var res = await fetch(baseUrl + '/api/activities-archive.php?action=preview&scope=' + scope + '&month=' + month + '&year=' + year, {
        headers: { 'Accept': 'application/json' }
      });
      var data = await res.json();

      if (data && data.success) {
        var count = data.count || 0;
        badge.textContent = count + ' Found';

        if (count > 0) {
          badge.style.background = '#10B981'; // Green
          var scopeLabel = (scope === 'month') 
            ? 'in ' + (data.month_name || '') + ' ' + year 
            : 'ready to archive';
          text.textContent = 'Found ' + count + ' completed activities ' + scopeLabel + '.';
          
          if (btn) {
            btn.disabled = false;
            btn.style.opacity = '1';
            btn.style.cursor = 'pointer';
          }

          if (list && data.activities && data.activities.length > 0) {
            list.innerHTML = '';
            data.activities.forEach(function(act) {
              var li = document.createElement('li');
              li.textContent = act.title + ' (' + act.date + ')';
              list.appendChild(li);
            });
            list.style.display = 'block';
          }
        } else {
          badge.style.background = 'var(--text-muted, #64748b)';
          var noMsg = (scope === 'month') 
            ? 'No unarchived completed activities found in ' + (data.month_name || '') + ' ' + year + '.'
            : 'No unarchived completed activities found.';
          text.textContent = noMsg;
          if (btn) {
            btn.disabled = true;
            btn.style.opacity = '0.5';
            btn.style.cursor = 'not-allowed';
          }
        }
      } else {
        badge.textContent = 'Error';
        badge.style.background = '#EF4444';
        text.textContent = data.error || 'Failed to check completed activities.';
      }
    } catch (e) {
      badge.textContent = 'Error';
      badge.style.background = '#EF4444';
      text.textContent = 'Network or server error checking activities.';
    }
  };

  window.submitUniversalArchive = async function() {
    var radios = document.getElementsByName('stiArchiveScope');
    var scope = 'month';
    for (var i = 0; i < radios.length; i++) {
      if (radios[i].checked) scope = radios[i].value;
    }

    var month = document.getElementById('stiArchiveMonth') ? document.getElementById('stiArchiveMonth').value : 1;
    var year  = document.getElementById('stiArchiveYear') ? document.getElementById('stiArchiveYear').value : new Date().getFullYear();
    var monthEl = document.getElementById('stiArchiveMonth');
    var monthName = monthEl ? monthEl.options[monthEl.selectedIndex].text : '';

    var confirmMsg = (scope === 'month')
      ? 'Are you sure you want to archive all activities completed in ' + monthName + ' ' + year + '?'
      : 'Are you sure you want to archive all completed activities?';

    var confirmed = false;
    if (window.STIModal && typeof window.STIModal.confirm === 'function') {
      confirmed = await window.STIModal.confirm(confirmMsg, {
        title: 'Archive Completed Activities?',
        icon: '📦',
        confirmText: 'Yes, Archive Activities',
        cancelText: 'Cancel',
        variant: 'info'
      });
    } else {
      confirmed = window.confirm(confirmMsg);
    }

    if (!confirmed) return;

    var btn = document.getElementById('stiBtnExecuteArchive');
    var btnText = document.getElementById('stiBtnExecuteArchiveText');
    if (btn) { btn.disabled = true; btn.style.opacity = '0.7'; }
    if (btnText) btnText.textContent = 'Archiving…';

    try {
      var res = await fetch(baseUrl + '/api/activities-archive.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({ action: 'archive', scope: scope, month: month, year: year })
      });
      var data = await res.json();

      if (data && data.success) {
        closeUniversalArchiveModal();
        if (window.STIModal && typeof window.STIModal.alert === 'function') {
          await window.STIModal.alert(data.message || 'Activities archived successfully.', { title: 'Archived', icon: '✅', variant: 'success' });
        } else {
          alert(data.message || 'Activities archived successfully.');
        }
        window.location.reload();
      } else {
        alert(data.error || 'Failed to archive activities.');
        if (btn) { btn.disabled = false; btn.style.opacity = '1'; }
        if (btnText) btnText.textContent = 'Archive Activities';
      }
    } catch (e) {
      alert('An unexpected error occurred while archiving activities.');
      if (btn) { btn.disabled = false; btn.style.opacity = '1'; }
      if (btnText) btnText.textContent = 'Archive Activities';
    }
  };

  // Helper to unarchive a single activity
  window.confirmUnarchiveActivity = async function(activityId, title) {
    if (!activityId) return;
    var name = title ? '"' + title + '"' : 'this activity';
    var confirmMsg = 'Restore ' + name + ' from the archive back to active status?';

    var confirmed = false;
    if (window.STIModal && typeof window.STIModal.confirm === 'function') {
      confirmed = await window.STIModal.confirm(confirmMsg, {
        title: 'Restore Activity?',
        icon: '📤',
        confirmText: 'Yes, Restore',
        cancelText: 'Cancel',
        variant: 'info'
      });
    } else {
      confirmed = window.confirm(confirmMsg);
    }

    if (!confirmed) return;

    try {
      var res = await fetch(baseUrl + '/api/activities-archive.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({ action: 'unarchive', id: activityId })
      });
      var data = await res.json();
      if (data && data.success) {
        if (window.STIModal && typeof window.STIModal.alert === 'function') {
          await window.STIModal.alert(data.message || 'Activity restored successfully.', { title: 'Restored', icon: '✅', variant: 'success' });
        } else {
          alert(data.message || 'Activity restored successfully.');
        }
        window.location.reload();
      } else {
        alert(data.error || 'Failed to unarchive activity.');
      }
    } catch (e) {
      alert('An unexpected error occurred while unarchiving the activity.');
    }
  };

  // Helper to archive a single completed activity
  window.confirmArchiveSingleActivity = async function(activityId, title) {
    if (!activityId) return;
    var name = title ? '"' + title + '"' : 'this completed activity';
    var confirmMsg = 'Archive ' + name + '? It will be moved to the archive list and can be restored anytime.';

    var confirmed = false;
    if (window.STIModal && typeof window.STIModal.confirm === 'function') {
      confirmed = await window.STIModal.confirm(confirmMsg, {
        title: 'Archive Activity?',
        icon: '📦',
        confirmText: 'Yes, Archive',
        cancelText: 'Cancel',
        variant: 'info'
      });
    } else {
      confirmed = window.confirm(confirmMsg);
    }

    if (!confirmed) return;

    try {
      var res = await fetch(baseUrl + '/api/activities-archive.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({ action: 'archive', id: activityId })
      });
      var data = await res.json();
      if (data && data.success) {
        if (window.STIModal && typeof window.STIModal.alert === 'function') {
          await window.STIModal.alert(data.message || 'Activity archived successfully.', { title: 'Archived', icon: '✅', variant: 'success' });
        } else {
          alert(data.message || 'Activity archived successfully.');
        }
        window.location.reload();
      } else {
        alert(data.error || 'Failed to archive activity.');
      }
    } catch (e) {
      alert('An unexpected error occurred while archiving the activity.');
    }
  };
})();
</script>

