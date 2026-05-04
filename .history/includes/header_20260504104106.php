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

	#notifBell + .dropdown-menu[aria-labelledby="notifBell"] {
		width: min(92vw, 600px);
		max-height: 620px;
		overflow: hidden;
		padding: 0;
		border-radius: 14px;
		border: 1px solid #d9e1ec;
		box-shadow: 0 20px 42px rgba(15, 39, 74, 0.25);
	}

	#notifBell + .dropdown-menu[aria-labelledby="notifBell"] .notif-head {
		position: sticky;
		top: 0;
		z-index: 2;
		padding: 12px 14px;
		background: linear-gradient(180deg, #295391 0%, #234a82 100%);
		color: #fff;
		border-bottom: 1px solid rgba(255, 255, 255, 0.25);
	}

	#notifBell + .dropdown-menu[aria-labelledby="notifBell"] .notif-mark-all {
		border: 0;
		background: transparent;
		color: #dce8fb;
		font-size: 0.84rem;
		font-weight: 600;
	}

	#notifBell + .dropdown-menu[aria-labelledby="notifBell"] .notif-mark-all:hover {
		color: #fff;
		text-decoration: underline;
	}

	#notifBell + .dropdown-menu[aria-labelledby="notifBell"] #notificationList {
		max-height: 530px;
		overflow-y: auto;
		overflow-x: hidden;
		background: #f0f2f5;
	}

	#notifBell + .dropdown-menu[aria-labelledby="notifBell"] .notif-item {
		background: #fff;
		margin: 8px;
		border: 1px solid #e2e8f0;
		border-radius: 12px;
		padding: 12px;
		white-space: normal;
		transition: all 0.22s ease;
	}

	#notifBell + .dropdown-menu[aria-labelledby="notifBell"] .notif-item.unread {
		background: #eaf3ff;
		border-color: #c7ddff;
	}

	#notifBell + .dropdown-menu[aria-labelledby="notifBell"] .notif-item:hover {
		background: #f8fbff;
		border-color: #bfd6f7;
	}

	#notifBell + .dropdown-menu[aria-labelledby="notifBell"] .notif-title {
		font-size: 0.95rem;
		font-weight: 700;
		line-height: 1.35;
		color: #1c2b3d;
	}

	#notifBell + .dropdown-menu[aria-labelledby="notifBell"] .notif-message {
		margin-top: 4px;
		font-size: 0.86rem;
		line-height: 1.45;
		color: #334155;
	}

	#notifBell + .dropdown-menu[aria-labelledby="notifBell"] .notif-time {
		margin-top: 6px;
		font-size: 0.76rem;
		color: #64748b;
	}

	#notifBell + .dropdown-menu[aria-labelledby="notifBell"] .notif-read-btn {
		padding: 4px 10px;
		border-radius: 999px;
		font-size: 0.76rem;
		font-weight: 700;
	}

	#notifBell + .dropdown-menu[aria-labelledby="notifBell"] .notif-empty {
		padding: 30px 16px;
		text-align: center;
		font-size: 0.95rem;
		color: #64748b;
		background: #f8fafc;
	}
</style>
