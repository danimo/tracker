<?php if (!isset($action)) {
	$this->title($ticket['fahrplan_id'] . ' | ' . $ticket['title'] . ' | ');
} else {
	$this->title(mb_ucfirst($action) . ' lecture ' . $ticket['title'] . ' | ');
} ?>

<?= $this->render('tickets/view/_header', [
	'titlePrefix' => (isset($action))?
		h(mb_ucfirst($action)) . ' lecture ' :
		null,
	'showDetails' => !isset($action),
	'currentAction' => (isset($action))? $action : null
]); ?>

<?php if (!empty($action)) {
	echo $this->render('tickets/view/_action');
} ?>

<?php if (isset($parent)): ?>
	<h3>Parent</h3>
	<ul class="tickets">
		<?= $this->render('tickets/ticket', [
			'ticket' => $parent
		]); ?>
	</ul>
<?php endif; ?>

<?php if (isset($children) and $children->getRows() > 0): ?>
	<h3>Children</h3>
	<ul class="tickets">
		<?php foreach ($children as $child) {
			echo $this->render('tickets/ticket', [
				'ticket' => $child,
				'simulateTickets' => true
			]);
		} ?>
	</ul>
<?php endif; ?>

<?php if (isset($profile)): ?>
	<h3 class="table">Encoding profile</h3>
	
	<table class="default">
		<thead>
			<tr>
				<th width="20%">Name</th>
				<th>Version</th>
				<th width="10%"></th>
				<th width="13%"></th>
			</tr>
		</thead>
		<tbody>
			<td><?= $profile['name']; ?></td>
			<td>r<?= $profile['revision'] . ' – ' . $profile['description']; ?></td>
			<td>
				<?php if (User::isAllowed('encodingprofiles', 'edit')) {
					echo $this->linkTo('encodingprofiles', 'edit', $profile, 'edit profile');
				} ?>
			</td>
			<td class="link right">
				<?php if (User::isAllowed('tickets', 'jobfile')) {
					echo $this->linkTo('tickets', 'jobfile', $ticket, ['.xml'], $project, 'download jobfile');
				} ?>
			</td>
		</tbody>
	</table>
<?php endif; ?>

<h3 class="table">Properties</h3>

<?php if ($properties->getRows() > 0 or ($ticket['ticket_type'] === 'encoding' and empty($action))) {
	echo $this->render(
		'shared/properties', [
			'merged' => $ticket['ticket_type'] === 'encoding' and empty($action)
		]
	);
} ?>

<?php if (isset($import)): ?>
<div class="ticket-imported">
	Last imported
	<?= timeAgo($import['finished']); ?>
	<?= (!empty($import['version']))?
		('(<span aria-label="' . h($import['url']) .'" data-tooltip="true">' . h($import['version']) . '</span>)') :
		('<code>' . h($import['url']) . '</code>'); ?>
	by <?= $import->User['user_name']; ?>.
</div>
<?php endif; ?>

<?php if (isset($parentProperties)) {
	echo $this->render('shared/properties', ['properties' => $parentProperties]);
}

if (isset($recordingProperties)) {
	echo $this->render('shared/properties', ['properties' => $recordingProperties]);
} ?>

<div id="timeline">
	<h3>Timeline</h3>
	<div class="line"></div>
	<ul class="clearfix">
		<?php if (empty($action) and User::isAllowed('tickets', 'comment')): ?>
			<li class="event left">
				<?php echo $f = $commentForm(); ?>
						<fieldset>
						<ul>
							<li><?php echo $f->textarea('text', null, null, array('class' => 'wide')); ?></li>
							<li>
								<?php echo $f->checkbox('needs_attention', 'Ticket group needs attention', $ticket->needsAttention());
								echo $f->submit('Comment'); ?>
							</li>
						</ul>
					</fieldset>
				</form>
			</li>
		<?php endif;
		
		$log = $log->getIterator();
		
		foreach ($comments as $comment) {
			while (strtotime($log->current()['created']) > strtotime($comment['created'])) {
				echo $this->render('tickets/view/_log_entry', ['entry' => $log->current()]);
				$log->next();
			}
			
			echo $this->render('tickets/view/_comment', ['comment' => $comment]);
		}
		
		while ($log->current()) {
			echo $this->render('tickets/view/_log_entry', ['entry' => $log->current()]);
			$log->next();
		} ?>
	</ul>
</div>