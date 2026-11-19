<?php
declare(strict_types=1);

/**
 * Validates and stores an uploaded image.
 *  - type decided by file CONTENT (finfo, or getimagesize when the fileinfo extension is off), never by the client's name or MIME
 *  - random filename + extension from the detected type
 *  - $public=true  -> assets/uploads/ (served; .htaccess disables script execution)
 *    $public=false -> storage/proofs/ (not web-accessible; streamed by admin/proof.php)
 *
 * @return string relative stored path, e.g. "assets/uploads/ab12....jpg" or "proofs/ab12....png"
 * @throws UserError
 */
function store_uploaded_image(array $file, bool $public = true): string
{
    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    $name = mb_substr((string) ($file['name'] ?? 'image'), 0, 80);
    $max = upload_max_bytes();
    switch ($err) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            throw new UserError("$name is too large. Each image can be up to " . round($max / 1048576, 1) . ' MB.');
        case UPLOAD_ERR_PARTIAL:
            throw new UserError("$name was only partly received. Please try the upload again.");
        case UPLOAD_ERR_NO_FILE:
            throw new UserError('No image was received. Choose a JPG, PNG or WebP file.');
        case UPLOAD_ERR_NO_TMP_DIR:
        case UPLOAD_ERR_CANT_WRITE:
            throw new UserError('PHP could not save the upload to its temporary folder (check upload_tmp_dir in php.ini and free disk space).');
        default:
            throw new UserError("$name was blocked by the server (upload error $err).");
    }
    if (!is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
        throw new UserError('No image was received. Choose a JPG, PNG or WebP file.');
    }
    if ((int) $file['size'] <= 0 || (int) $file['size'] > $max) {
        throw new UserError("$name is too large. Each image can be up to " . round($max / 1048576, 1) . ' MB.');
    }
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $info = @getimagesize($file['tmp_name']);
    if (class_exists('finfo')) {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    } else {
        $mime = $info['mime'] ?? '';
    }
    if (!isset($allowed[$mime])) {
        throw new UserError("$name is not a JPG, PNG or WebP image.");
    }
    if ($info === false || $info[0] < 1 || $info[1] < 1 || $info[0] > 20000 || $info[1] > 20000) {
        throw new UserError("$name is not a readable image.");
    }
    $stored = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    $dirRel = $public ? 'assets/uploads' : 'storage/proofs';
    $dir = APP_ROOT . '/' . $dirRel;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new UserError("Upload folder $dirRel is missing and could not be created.");
    }
    if (!is_writable($dir)) {
        throw new UserError("The server cannot write to the $dirRel folder. Give the web server write permission to it (on Windows: folder Properties → Security).");
    }
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $stored)) {
        throw new UserError("Could not write to $dirRel (check folder permissions).");
    }
    @chmod($dir . '/' . $stored, 0644);
    return $public ? "assets/uploads/$stored" : "proofs/$stored";
}

/** Deletes a file previously stored by store_uploaded_image (only inside the upload folders). */
function delete_uploaded_file(?string $rel): void
{
    $rel = (string) $rel;
    if (preg_match('#^assets/uploads/[a-f0-9]{32}\.(jpg|png|webp)$#', $rel)) {
        @unlink(APP_ROOT . '/' . $rel);
    } elseif (preg_match('#^proofs/[a-f0-9]{32}\.(jpg|png|webp)$#', $rel)) {
        @unlink(APP_ROOT . '/storage/' . $rel);
    }
}

/** Deletes an uploaded image only when no gallery photo, area, package or website content still uses it. */
function delete_image_if_unused(?string $rel): void
{
    $rel = (string) $rel;
    if ($rel === '' || !preg_match('#^assets/uploads/#', $rel)) {
        return;
    }
    $uses = (int) q_val('SELECT (SELECT COUNT(*) FROM gallery WHERE image_path = ?) + (SELECT COUNT(*) FROM resort_areas WHERE main_image = ?)
                              + (SELECT COUNT(*) FROM website_content WHERE content_value = ?) + (SELECT COUNT(*) FROM packages WHERE image = ?)', [$rel, $rel, $rel, $rel]);
    if ($uses === 0) {
        delete_uploaded_file($rel);
    }
}

function has_upload(string $field): bool
{
    return isset($_FILES[$field]) && is_array($_FILES[$field]) && ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
}
