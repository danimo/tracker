<?php
	
	requires(
		'String',
		'/Model/Ticket'
	);
	
	class Controller_Import extends Controller_Application {
		
		public $requireAuthorization = true;
		
		const FAHRPLAN_FILES = 'Public/fahrplan/';
		
		/*
		public $beforeFilter = true;
		
		public function beforeFilter($arguments, $action) {
			if ($this->Project->read_only) {
				$this->flash('You can\'t import tickets to this project because it\'s locked');
				$this->View->redirect('tickets', 'index', array('project_slug' => $this->Project->slug));
				return false;
			}
			
			return true;
		}
		*/
		
		public function index() {
			$this->form = $this->form('import', 'review', $this->project->toArray());
			
			if (file_exists(ROOT . 'Public/fahrplan/')) {
				$files = $this->_getFiles();
				$this->files = (!empty($files))? array('' => '') + $files : array();
			}
			
			// $this->View->assign('projectProperties', $this->ProjectProperties->findByObject($this->Project->id));
			return $this->render('import/index.tpl');
		}
		
		public function review() {
			$this->form = $this->form();
			$this->applyForm = $this->form('import', 'apply', $this->project->toArray());
			
			if (!$this->form->wasSubmitted()) {
				return $this->redirect('import', 'index', $this->project->toArray());
			}
			
			if (!$xml = $this->_loadXML($this->form)) {
				return $this->redirect('import', 'index', $this->project->toArray());
			}
			
			$tickets = Ticket::findAll(array())
				->where(array('ticket_type' => 'meta', 'project_id' => $this->project['id']))
				->indexBy('fahrplan_id')
				->toArray();
			
			// TODO: day change is missing in XML file
			$dayChange = '04:00';
			
			$events = $xml->xpath('day/room/event');
			
			$this->tickets = array(
				'new' => [],
				'changed' => [],
				'deleted' => []
			);
			
			foreach ($events as $event) {
				$properties = array();
				
				$properties['Fahrplan.ID'] = (int) $event->attributes()['id'];
				$properties['Fahrplan.GUID'] = (int) $event->attributes()['guid'];
				
				$properties['Fahrplan.Date'] = (string) current($event->xpath('ancestor::day/@date'));
				$properties['Fahrplan.Start'] = (string) $event->start;
				
				$eventStart = new DateTime($properties['Fahrplan.Date'] . ' ' . $properties['Fahrplan.Start']);
				$eventDayChange = new DateTime($properties['Fahrplan.Date'] . ' ' . $dayChange);
				
				if ($eventStart < $eventDayChange) {
					$properties['Fahrplan.Date'] = $eventStart
						->modify('+1 day')
						->format('Y-m-d');
				}
				
				$properties['Fahrplan.Day'] = (string) current($event->xpath('ancestor::day/@index'));
				$properties['Fahrplan.Duration'] = (string) $event->duration;
				$properties['Fahrplan.Room'] = (string) $event->room;
				
				$properties['Fahrplan.Slug'] = (string) $event->slug;
				$properties['Fahrplan.Title'] = (string) $event->title;
				$properties['Fahrplan.Subtitle'] = (string) $event->subtitle;
				$properties['Fahrplan.Track'] = (string) $event->track;
				$properties['Fahrplan.Type'] = (string) $event->type;
				$properties['Fahrplan.Language'] = (string) $event->language;

				$properties['Fahrplan.Abstract'] = (string) $event->abstract;
				
				$properties['Fahrplan.Person_list'] = implode(', ', $event->xpath('persons/person'));
				
				foreach ($properties as $property => $value) {
					if (empty($value)) {
						unset($properties[$property]);
					}
				}
				
				if (!isset($tickets[$properties['Fahrplan.ID']])) {
					if (isset($this->tickets['new'][$properties['Fahrplan.ID']])) {
						// TODO: warning duplicate fahrplan id
						continue;
					}
					
					$this->tickets['new'][$properties['Fahrplan.ID']] = $properties;
					continue;
				}
				
				$ticketProperties = (new Ticket($tickets[$properties['Fahrplan.ID']]))
					->Properties
					->indexBy('name', 'value')
					->toArray();
				
				$this->tickets['changed'][$properties['Fahrplan.ID']] = array(
					'properties' => $properties,
					'diff' => []
				);
				
				foreach (array_merge($properties, $ticketProperties) as $key => $value) {
					$property = [
						'fahrplan' => (isset($properties[$key]))? $properties[$key] : null,
						'database' => (isset($ticketProperties[$key]))? $ticketProperties[$key] : null
					];
					
					// TODO: strcmp(null, '…')?
					if (strcmp($property['fahrplan'], $property['database']) == 0) {
						continue;
					}
					
					$this->tickets['changed'][$properties['Fahrplan.ID']]['diff'][$key] = $property;
				}
				
				if (empty($this->tickets['changed'][$properties['Fahrplan.ID']]['diff'])) {
					// remove ticket from list, so it hides from array_diff below
					unset($tickets[$properties['Fahrplan.ID']]);
					unset($this->tickets['changed'][$properties['Fahrplan.ID']]);
				}
			}
			
			$this->tickets['deleted'] = (empty($tickets))? array() :
				array_diff_key($tickets, $this->tickets['changed']);
			// TODO: array_fill_key true
			
			if (empty($this->tickets['new']) and empty($this->tickets['changed']) and empty($this->tickets['deleted'])) {
				$this->flash('Fahrplan has not changed since last update');
				return $this->redirect('import', 'index', $this->project->toArray());
			}
			
			requiresSession();
			// TODO: use form token to support multiple imports?
			// TODO: should we add a project wide lock?
			$_SESSION['import'] = $this->tickets;
			
			return $this->render('import/review.tpl');
		}
		
		
		public function apply() {
			if (!isset($_SESSION['import'])) {
				return $this->redirect('import', 'index', $this->project->toArray());
			}
			
			$ticketsChanged = 0;
			$tickets = $this->form()->getValue('tickets');
			
			Database::$Instance->beginTransaction();
			
			if (isset($tickets['new'])) {
				// remove unchecked entries
				$tickets['new'] = array_filter($tickets['new']);
			
				foreach (array_intersect_key($_SESSION['import']['new'], $tickets['new']) as $fahrplanID => $properties) {
					$ticketProperties = array();
				
					foreach ($properties as $key => $value) {
						$ticketProperties[] = ['name' => $key, 'value' => $value];
					}
				
					if (Ticket::create(array(
						'project_id' => $this->project['id'],
						'fahrplan_id' => $properties['Fahrplan.ID'],
						'title' => $properties['Fahrplan.Title'],
						'ticket_type' => 'meta',
						'ticket_state' => 'staging',
						'properties' => $ticketProperties
					))) {
						$ticketsChanged++;
					}
				}
			}
			
			if (isset($tickets['change'])) {
				$tickets['change'] = array_filter($tickets['change']);
			
				foreach (array_intersect_key($_SESSION['import']['changed'], $tickets['change']) as $fahrplanID => $changed) {
					$ticket = Ticket::findBy(array('fahrplan_id' => $fahrplanID), [], []);
				
					$ticket['title'] = $changed['properties']['Fahrplan.Title'];
					$properties = array();
				
					foreach($changed['diff'] as $key => $property) {
						if ($property['fahrplan'] === null) {
							$properties[] = array(
								'name' => $key,
								'_destroy' => true
							);
							continue;
						}
					
						$properties[] = array(
							'name' => $key,
							'value' => $property['fahrplan']
						);
					}
				
					if (!empty($properties)) {
						$ticket['properties'] = $properties;
					}
				
					if ($ticket->save()) {
						$ticketsChanged++;
					}
				}
			}
			
			if (isset($tickets['delete'])) {
				$tickets['delete'] = array_filter($tickets['delete']);
			
				foreach (array_intersect_key($_SESSION['import']['deleted'], $tickets['delete']) as $fahrplanID => $ticket) {
					if (Ticket::delete($ticket['id'])) {
						$ticketsChanged++;
					}
				}
			}
			
			Database::$Instance->commit();
			
			unset($_SESSION['import']);
			
			$this->flash('Updated ' . $ticketsChanged . ' ticket' . (($ticketsChanged > 1)? 's' : ''));
			return $this->redirect('import', 'index', $this->project->toArray());
		}
		
		public function _getFiles() {
			$files = array();
			
			foreach (new DirectoryIterator(ROOT . 'Public/fahrplan/') as $file) {
				if (mb_substr($file->getFilename(), -4) == '.xml') {
					$files[$file->getFilename()] = mb_substr($file->getFilename(), 0, -4);
				}
			}
			
			natsort($files);
			$files = array_reverse($files,true);
			
			return $files;
		}
		
		public function _loadXML(Form $form) {
			if ($file = $form->getvalue('file')) {
				$files = $this->_getFiles();
				
				if (!isset($files[$file])) {
					$this->flash('Invalid file');
					return false;
				}
				
				$path = ROOT . self::FAHRPLAN_FILES . $file;
				
				if (!isset($path)) {
					$this->flash('Could not read file');
					return false;
				}
				
				if (!$xml = simplexml_load_file($path)) {
					$this->flash('Could not parse XML');
					return false;
				}
				
				return $xml;
			}
			
			$curl = curl_init($form->getValue('url'));
			
			curl_setopt_array($curl, array(
				CURLOPT_USERAGENT => 'FeM-Tracker/1.0 (http://fem.tu-ilmenau.de)',
				CURLOPT_RETURNTRANSFER => true
			));
			
			if (!$xml = curl_exec($curl)) {
				$this->flash('Request to XML URL failed');
				return false;
			}
			
			if (!$xml = simplexml_load_string($xml)) {
				$this->flash('Could not parse XML');
				return false;
			}
			
			return $xml;
		}
		
	}
	
	class TicketsImportException extends Exception {}
	
?>