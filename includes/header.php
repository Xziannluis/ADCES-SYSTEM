<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="../assets/css/style.css?v=20260502b">
<style>
	body,
	button,
	input,
	select,
	textarea,
	.navbar,
	.card,
	.modal,
	.dropdown-menu {
		font-family: Arial, Helvetica, sans-serif !important;
	}

	.user-menu-btn {
		display: inline-flex;
		align-items: center;
		gap: 0.5rem;
		padding: 0.6rem 1rem;
		border: 1px solid #d0d7de;
		border-radius: 999px;
		background: #ffffff;
		color: #2c3e50;
		font-weight: 600;
		line-height: 1.2;
		box-shadow: 0 2px 8px rgba(44, 62, 80, 0.08);
		transition: all 0.2s ease;
	}

	.user-menu-btn:hover,
	.user-menu-btn:focus,
	.user-menu-btn.show {
		background: #2c3e50;
		border-color: #2c3e50;
		color: #ffffff;
		box-shadow: 0 6px 18px rgba(44, 62, 80, 0.18);
	}

	.user-menu-btn::after {
		margin-left: 0.35rem;
	}

	.dropdown-menu[aria-labelledby$="Menu"],
	.dropdown-menu[aria-labelledby="userDropdown"] {
		border: 1px solid rgba(44, 62, 80, 0.08);
		border-radius: 0.9rem;
		box-shadow: 0 12px 30px rgba(44, 62, 80, 0.14);
		padding: 0.45rem;
	}

	.dropdown-menu .dropdown-item {
		border-radius: 0.7rem;
		padding: 0.6rem 0.85rem;
		font-weight: 500;
	}

	.dropdown-menu .dropdown-item:hover,
	.dropdown-menu .dropdown-item:focus {
		background: rgba(44, 62, 80, 0.08);
		color: #2c3e50;
	}

	@media (max-width: 991.98px) {
		.dropdown-menu[aria-labelledby$="Menu"],
		.dropdown-menu[aria-labelledby="userDropdown"] {
			width: min(92vw, 320px);
		}

		.user-menu-btn {
			width: 100%;
			justify-content: center;
		}
	}

	/* Facebook-like notification dropdown */
	#notifBell {
		color: #fff;
		font-size: 1.3rem;
	}

	/* Notification dropdown - larger panel matching the design mock */
	#notifBell + .dropdown-menu[aria-labelledby="notifBell"] {
		width: 420px;
		max-width: calc(95vw - 20px);
		max-height: 720px;
		overflow: hidden;
		padding: 0;
		border-radius: 14px;
		border: 1px solid #e6eef8;
		box-shadow: 0 20px 42px rgba(15, 39, 74, 0.25);
		right: 0;
	}

	#notifBell + .dropdown-menu[aria-labelledby="notifBell"] .notif-head {
		position: sticky;
		top: 0;
		z-index: 2;
		padding: 18px 20px;
		background: #ffffff;
		color: #1c2b3d;
		border-bottom: 1px solid rgba(15, 39, 74, 0.06);
		font-size: 1.05rem;
		font-weight: 700;
		display: flex;
		align-items: center;
		justify-content: space-between;
	}

	#notifBell + .dropdown-menu[aria-labelledby="notifBell"] .notif-mark-all {
		border: 0;
		background: transparent;
		color: #3366cc;
		font-size: 0.92rem;
		font-weight: 700;
		cursor: pointer;
	}

	#notifBell + .dropdown-menu[aria-labelledby="notifBell"] .notif-mark-all:hover {
		text-decoration: underline;
	}

	#notifBell + .dropdown-menu[aria-labelledby="notifBell"] #notificationList {
		max-height: 620px;
		overflow-y: auto;
		overflow-x: hidden;
		background: transparent;
		padding: 12px 14px 18px 14px;
	}

	#notifBell + .dropdown-menu[aria-labelledby="notifBell"] .notif-item {
		background: #fff;
		margin: 10px 0;
		border: 1px solid #edf2f7;
		border-radius: 12px;
		padding: 14px;
		white-space: normal;
		transition: all 0.18s ease;
		display: flex;
		gap: 12px;
		align-items: flex-start;
	}

	#notifBell + .dropdown-menu[aria-labelledby="notifBell"] .notif-item.unread {
		background: #f0f7ff;
		border-color: #dbeeff;
	}

	#notifBell + .dropdown-menu[aria-labelledby="notifBell"] .notif-item:hover {
		transform: translateY(-2px);
		box-shadow: 0 6px 18px rgba(15,39,74,0.06);
	}

	#notifBell + .dropdown-menu[aria-labelledby="notifBell"] .notif-title {
		font-size: 1rem;
		font-weight: 700;
		line-height: 1.35;
		color: #0f1724;
	}

	#notifBell + .dropdown-menu[aria-labelledby="notifBell"] .notif-message {
		margin-top: 6px;
		font-size: 0.95rem;
		line-height: 1.4;
		color: #334155;
	}

	#notifBell + .dropdown-menu[aria-labelledby="notifBell"] .notif-time {
		margin-top: 8px;
		font-size: 0.82rem;
		color: #64748b;
	}

	#notifBell + .dropdown-menu[aria-labelledby="notifBell"] .notif-read-btn {
		padding: 6px 12px;
		border-radius: 999px;
		font-size: 0.85rem;
		font-weight: 700;
	}

	#notifBell + .dropdown-menu[aria-labelledby="notifBell"] .notif-empty {
		padding: 36px 16px;
		text-align: center;
		font-size: 0.98rem;
		color: #64748b;
		background: #fff;
	}

	/* Avatar and footer helpers for notification items */
	#notifBell + .dropdown-menu[aria-labelledby="notifBell"] .notif-avatar {
		width: 44px;
		height: 44px;
		flex: 0 0 44px;
		border-radius: 50%;
		overflow: hidden;
		background: #f1f7fe;
		display: flex;
		align-items: center;
		justify-content: center;
		font-size: 20px;
		color: #3b82f6;
	}

	#notifBell + .dropdown-menu[aria-labelledby="notifBell"] .notif-avatar img {
		width: 100%;
		height: 100%;
		object-fit: cover;
		display: block;
	}

	#notifBell + .dropdown-menu[aria-labelledby="notifBell"] .notif-unread-dot {
		width: 8px;
		height: 8px;
		background: #0d6efd;
		border-radius: 50%;
		display: inline-block;
		margin-left: 8px;
		box-shadow: 0 0 0 4px rgba(13,110,253,0.06);
	}

	#notifBell + .dropdown-menu[aria-labelledby="notifBell"] .dropdown-footer {
		border-top: 1px solid rgba(15,39,74,0.04);
		padding: 12px 18px;
		background: #fff;
		display: flex;
		justify-content: space-between;
		align-items: center;
		gap: 12px;
	}

	@media (max-width: 991.98px) {
		#notifBell + .dropdown-menu[aria-labelledby="notifBell"] {
			width: min(92vw, 360px);
		}
	}
</style>
