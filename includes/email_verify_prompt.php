<?php
/**
 * Email Verification Prompt Modal
 * Include this on dashboard pages. Shows a one-time-per-session modal
 * prompting unverified users to verify their email address.
 */
if (isset($_SESSION['user_id']) && !isset($_SESSION['email_verify_prompt_shown'])):
    $__evpDb = (new Database())->getConnection();
    $__evpStmt = $__evpDb->prepare("SELECT email, is_email_verified FROM users WHERE id = :id LIMIT 1");
    $__evpStmt->execute([':id' => $_SESSION['user_id']]);
    $__evpUser = $__evpStmt->fetch(PDO::FETCH_ASSOC);

    if ($__evpUser && (int)($__evpUser['is_email_verified'] ?? 0) === 0):
        $_SESSION['email_verify_prompt_shown'] = true;
        $__settingsPath = match($_SESSION['role'] ?? '') {
            'president', 'vice_president' => '../evaluators/settings.php',
            'edp' => '../edp/settings.php',
            'teacher' => '../teachers/settings.php',
            default => '../evaluators/settings.php',
        };
?>
<!-- Email Verification Prompt Modal -->
<div class="modal fade" id="emailVerifyPromptModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered" style="max-width:460px;">
        <div class="modal-content border-0 shadow-lg" style="border-radius:16px;overflow:hidden;">
            <div class="modal-body text-center p-0">
                <!-- Top colored banner -->
                <div style="background:linear-gradient(135deg,#4285f4,#34a853);padding:28px 24px 20px;">
                    <div style="width:72px;height:72px;border-radius:50%;background:rgba(255,255,255,0.2);display:inline-flex;align-items:center;justify-content:center;margin-bottom:8px;">
                        <i class="fas fa-envelope" style="font-size:32px;color:#fff;"></i>
                    </div>
                    <h5 class="text-white fw-bold mb-0" style="font-size:1.2rem;">Email Verification Required</h5>
                </div>

                <!-- Content -->
                <div class="px-4 pt-3 pb-2">
                    <?php if (!empty($__evpUser['email'])): ?>
                        <p class="mb-2 text-muted" style="font-size:0.9rem;">Your email address</p>
                        <p class="fw-semibold mb-3" style="font-size:1rem;"><?php echo htmlspecialchars($__evpUser['email']); ?></p>
                        <p class="mb-1" style="font-size:0.92rem;">is <span class="badge bg-warning text-dark">Not Verified</span></p>
                    <?php else: ?>
                        <p class="fw-semibold mb-3" style="font-size:1rem;">You haven't set an email address yet.</p>
                    <?php endif; ?>

                    <hr class="my-3">

                    <p class="text-muted mb-2" style="font-size:0.88rem;">Please verify your email to enable these features:</p>

                    <div class="text-start mx-auto" style="max-width:320px;">
                        <div class="d-flex align-items-start mb-2">
                            <i class="fas fa-key text-primary me-2 mt-1" style="font-size:0.85rem;"></i>
                            <span style="font-size:0.88rem;"><strong>Password Recovery</strong> — Reset your password securely through a verification code sent to your email.</span>
                        </div>
                        <div class="d-flex align-items-start mb-2">
                            <i class="fas fa-calendar-check text-success me-2 mt-1" style="font-size:0.85rem;"></i>
                            <span style="font-size:0.88rem;"><strong>Schedule Notifications</strong> — Receive email alerts when an evaluation schedule is set for you.</span>
                        </div>
                        <div class="d-flex align-items-start mb-2">
                            <i class="fas fa-clipboard-check text-info me-2 mt-1" style="font-size:0.85rem;"></i>
                            <span style="font-size:0.88rem;"><strong>Evaluation Updates</strong> — Get notified via email when an evaluation has been submitted.</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 justify-content-center gap-2 pb-4 pt-2">
                <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal" style="border-radius:8px;">
                    Later
                </button>
                <a href="<?php echo $__settingsPath; ?>" class="btn btn-primary px-4" style="border-radius:8px;">
                    <i class="fas fa-cog me-1"></i>Go to Settings
                </a>
            </div>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var m = document.getElementById('emailVerifyPromptModal');
    if (m) { new bootstrap.Modal(m).show(); }
});
</script>
<?php
    endif;
endif;
?>
