<?php
// Expects: $notifications (array), $unread_count (int) to be set before including this file.
if (!isset($notifications)) $notifications = [];
if (!isset($unread_count)) $unread_count = 0;
?>
<?php if (!empty($notifications)): ?>
<div class="card mb-4">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0 text-white">
            <i class="fas fa-bell me-2"></i>Schedule Notifications
            <?php if ($unread_count > 0): ?>
                <span class="badge bg-danger ms-1"><?php echo $unread_count; ?> new</span>
            <?php endif; ?>
        </h5>
        <?php if ($unread_count > 0): ?>
        <button class="btn btn-sm btn-outline-light" onclick="markAllRead()">
            <i class="fas fa-check-double me-1"></i>Mark All Read
        </button>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <div class="list-group list-group-flush" id="notificationList">
            <?php foreach ($notifications as $notif): ?>
            <div class="list-group-item <?php echo $notif['is_read'] ? '' : 'list-group-item-warning'; ?>" id="notif-<?php echo (int)$notif['id']; ?>">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h6 class="mb-1">
                            <?php if (!$notif['is_read']): ?><i class="fas fa-circle text-danger me-1" style="font-size:0.5rem;vertical-align:middle;"></i><?php endif; ?>
                            <?php echo htmlspecialchars($notif['title']); ?>
                        </h6>
                        <p class="mb-1 text-muted small"><?php echo htmlspecialchars($notif['message']); ?></p>
                        <small class="text-muted"><?php echo date('M j, Y g:i A', strtotime($notif['created_at'])); ?></small>
                    </div>
                    <?php if (!$notif['is_read']): ?>
                    <button class="btn btn-sm btn-outline-secondary ms-2" onclick="markRead(<?php echo (int)$notif['id']; ?>)" title="Mark as read">
                        <i class="fas fa-check"></i>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<script>
function markRead(id) {
    fetch('../includes/notification_mark_read.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'id=' + id
    }).then(r => r.json()).then(d => {
        if (d.success) {
            const el = document.getElementById('notif-' + id);
            if (el) {
                el.classList.remove('list-group-item-warning');
                const btn = el.querySelector('button');
                if (btn) btn.remove();
                const dot = el.querySelector('.fa-circle');
                if (dot) dot.remove();
            }
        }
    });
}
function markAllRead() {
    fetch('../includes/notification_mark_read.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'mark_all=1'
    }).then(r => r.json()).then(d => {
        if (d.success) {
            document.querySelectorAll('#notificationList .list-group-item-warning').forEach(el => {
                el.classList.remove('list-group-item-warning');
                const btn = el.querySelector('button');
                if (btn) btn.remove();
                const dot = el.querySelector('.fa-circle');
                if (dot) dot.remove();
            });
            const badge = document.querySelector('.card-header .badge.bg-danger');
            if (badge) badge.remove();
            const markAllBtn = document.querySelector('[onclick="markAllRead()"]');
            if (markAllBtn) markAllBtn.remove();
        }
    });
}
</script>
<?php endif; ?>
