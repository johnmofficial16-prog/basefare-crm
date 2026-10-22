<?php
/**
 * Files on one message (messages.js draws the same markup for new ones).
 *
 * @var array $msgFiles ChatFiles::describe rows
 */
if (!empty($msgFiles)):
    $fileIcon = ['PDF' => 'picture_as_pdf', 'Word' => 'description', 'Excel' => 'table_chart', 'CSV' => 'table_chart', 'Text' => 'article', 'Image' => 'image'];
?>
<div class="msg-files">
  <?php foreach ($msgFiles as $f): $url = '/messages/file/' . (int) $f['id']; $n = htmlspecialchars($f['name'], ENT_QUOTES, 'UTF-8'); ?>
  <?php if ($f['image']): ?>
  <a class="msg-pic" href="<?= $url ?>" target="_blank" rel="noopener" title="<?= $n ?>"><img src="<?= $url ?>" alt="<?= $n ?>" loading="lazy"></a>
  <?php else: ?>
  <a class="msg-file" href="<?= $url ?>" target="_blank" rel="noopener"><span class="material-symbols-outlined"><?= $fileIcon[$f['kind']] ?? 'draft' ?></span><span class="msg-file__t"><b><?= $n ?></b><small><?= htmlspecialchars($f['kind'] . ' · ' . $f['size_label'], ENT_QUOTES, 'UTF-8') ?></small></span></a>
  <?php endif; ?>
  <?php endforeach; ?>
</div>
<?php endif; ?>
