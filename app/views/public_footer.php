</main>
<footer class="site-foot">
  <div class="wrap foot-grid">
    <div>
      <strong><?= e((string) setting('resort_name', "Agie's Resort")) ?></strong>
      <p><?= e(content('contact_address', '')) ?></p>
    </div>
    <div>
      <?php if (content('contact_phone') !== ''): ?><p>Phone: <?= e(content('contact_phone')) ?></p><?php endif; ?>
      <?php if (content('contact_email') !== ''): ?><p>Email: <?= e(content('contact_email')) ?></p><?php endif; ?>
      <?php $fb = (string) setting('facebook_url', ''); if (preg_match('#^https://#', $fb)): ?><p><a href="<?= e($fb) ?>" rel="noopener" target="_blank">Facebook page</a></p><?php endif; ?>
    </div>
    <div>
      <p><a href="<?= e(url('policies.php')) ?>">Resort policies</a></p>
      <p><a href="<?= e(url('my-booking.php')) ?>">Check my booking</a></p>
      <p><a href="<?= e(url('admin/login.php')) ?>">Staff sign in</a></p>
    </div>
  </div>
  <p class="wrap foot-note">&copy; <?= date('Y') ?> <?= e((string) setting('resort_name', "Agie's Resort")) ?></p>
</footer>
<script src="<?= e(asset('assets/js/app.js')) ?>"></script>
</body>
</html>
