<li class="event log right">
	<span class="title"><?php if (($message = $entry->getMessage()) !== false) {
		echo $message;
	} else {
		echo '<em>' . $entry['event'] . '</em>';
	} ?></span>
	<?php if (!empty($entry['comment'])): ?>
		<code>
			<?php $lines = $this->h();
			
			echo nl2br(
				implode(
					'<br />',
					array_slice(
						array_filter(explode("\n", $this->h($entry['comment']))),
						0,
						3
					)
				)
			);
			
			if (count($lines) > 3) {
				echo ' ' . $this->linkTo('tickets', 'log', $ticket, array('entry' => $entry['id']), $project + array('.txt'), 'more');
			} ?>
		</code>
	<?php endif; ?>

	<span class="date">
		<?php echo /*Date::distanceInWords(new Date($entry['created'])); ?> ago<span>: <?php echo*/ (new DateTime($entry['created']))->format('D, M j Y, H:i'); ?><!--</span>-->
	</span>
	<span class="description"> by <?php echo $entry['handle_name']; ?></span>
	<span class="spine"></span>
</li>