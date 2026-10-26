<?php
/* Shared "My profile" panel. Expects $profileApi (relative URL) and $profileEmailEditable (bool). */
$profileEmailEditable = $profileEmailEditable ?? false;
?>
<section class="profile-grid" id="profile-section" data-api="<?= htmlspecialchars($profileApi, ENT_QUOTES, 'UTF-8') ?>">
<div class="panel"><h2>Personal details</h2>
<form id="profile-form" novalidate>
<div class="form-grid">
<div class="field"><label for="p-first">First name</label><input id="p-first" name="first_name" maxlength="60" required autocomplete="given-name"></div>
<div class="field"><label for="p-middle">Middle name (optional)</label><input id="p-middle" name="middle_name" maxlength="60" autocomplete="additional-name"></div>
<div class="field"><label for="p-last">Last name</label><input id="p-last" name="last_name" maxlength="60" required autocomplete="family-name"></div>
<div class="field"><label for="p-ext">Name extension</label><select id="p-ext" name="name_extension"><option value="">None</option><option>Jr.</option><option>Sr.</option><option>II</option><option>III</option><option>IV</option><option>V</option></select></div>
<div class="field"><label for="p-email"><?= $profileEmailEditable ? 'Email' : 'Login email (username)' ?></label><input id="p-email" name="email" type="email" maxlength="190" autocomplete="email" <?= $profileEmailEditable ? 'required' : 'readonly' ?>><?php if (!$profileEmailEditable): ?><span class="hint">Your username is set by an administrator and cannot be changed here.</span><?php endif; ?></div>
<div class="field"><label for="p-contact">Contact number</label><input id="p-contact" name="contact_number" maxlength="20" inputmode="tel" autocomplete="tel" placeholder="09171234567"></div>
<div class="field"><label for="p-birth">Birthdate</label><input id="p-birth" name="birthdate" type="date" required></div>
<div class="field"><label for="p-age">Age</label><input id="p-age" type="text" readonly tabindex="-1"></div>
<div class="field wide"><label for="p-address">Address</label><input id="p-address" name="address" maxlength="255" autocomplete="street-address"></div>
</div>
<div id="profile-msg" class="msg" role="alert"></div>
<div class="actions"><button type="button" class="btn ghost" id="profile-reset">Discard changes</button><button type="submit" class="btn">Save changes</button></div>
</form></div>
<div class="stack">
<div class="panel"><h2>Account</h2><dl class="meta-list" id="profile-meta"><div><dt>Loading…</dt><dd></dd></div></dl></div>
<div class="panel"><h2>Change password</h2>
<form id="password-form" novalidate>
<div class="field"><label for="pw-current">Current password</label><input id="pw-current" name="current_password" type="password" autocomplete="current-password" required></div>
<div class="field"><label for="pw-new">New password</label><input id="pw-new" name="new_password" type="password" minlength="8" maxlength="255" autocomplete="new-password" required><span class="hint">At least 8 characters.</span></div>
<div class="field"><label for="pw-confirm">Confirm new password</label><input id="pw-confirm" name="confirm_password" type="password" maxlength="255" autocomplete="new-password" required></div>
<div id="password-msg" class="msg" role="alert"></div>
<div class="actions"><button type="submit" class="btn">Update password</button></div>
</form></div>
</div>
</section>
