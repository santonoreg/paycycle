</div><!-- /.container-fluid -->
<footer class="text-center text-muted small py-4">
  <?= te('app.name') ?> &middot; <?= date('Y') ?>
</footer>
<script>
window.I18N = <?= json_encode([
    'locale'     => t('meta.locale'),
    'dateFormat' => t('meta.date_format'),
    'freq'       => frequencies(),
    'status'     => statuses(),
    'js'         => array_combine(
        ['installments_total', 'no_payments', 'no_prices', 'from', 'delete_price', 'no_freezes', 'until_today', 'confirm_delete_price', 'awaiting_bill', 'save_amount', 'no_activity', 'dup_title', 'dup_hint', 'new_title', 'legacy_method', 'prepayment', 'prepay_full', 'prepay_remaining', 'prepay_label', 'prepay_count', 'prepay_save', 'prepay_delete', 'prepay_delete_confirm'],
        [t('js.installments_total'), t('js.no_payments'), t('js.no_prices'), t('js.from'), t('js.delete_price'), t('js.no_freezes'), t('js.until_today'), t('confirm.delete_price'), t('var.badge'), t('var.save_amount'), t('js.no_activity'), t('js.dup_title'), t('js.dup_hint'), t('modal.new_sub'), t('js.legacy_method'), t('js.prepayment'), t('prepay.full'), t('prepay.remaining'), t('prepay.title'), t('prepay.count'), t('common.save'), t('js.prepay_delete'), t('js.prepay_delete_confirm')]
    ),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/app.js?v=<?= filemtime(__DIR__ . '/../assets/app.js') ?>"></script>
</body>
</html>
