<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="../assets/css/style.css">
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
</style>