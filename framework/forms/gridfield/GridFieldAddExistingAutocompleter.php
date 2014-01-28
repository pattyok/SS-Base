<?php
/**
 * This class is is responsible for adding objects to another object's has_many 
 * and many_many relation, as defined by the {@link RelationList} passed to the 
 * {@link GridField} constructor.
 *
 * Objects can be searched through an input field (partially matching one or 
 * more fields).
 *
 * Selecting from the results will add the object to the relation.
 *
 * Often used alongside {@link GridFieldDeleteAction} for detaching existing 
 * records from a relationship.
 *
 * For easier setup, have a look at a sample configuration in 
 * {@link GridFieldConfig_RelationEditor}.
 *
 * @package framework
 * @subpackage fields-gridfield
 */
class GridFieldAddExistingAutocompleter 
	implements GridField_HTMLProvider, GridField_ActionProvider, GridField_DataManipulator, GridField_URLHandler {
	
	/**
	 * Which template to use for rendering
	 * 
	 * @var string $itemClass
	 */
	protected $itemClass = 'GridFieldAddExistingAutocompleter';

	/**
	 * The HTML fragment to write this component into
	 */
	protected $targetFragment;

	/**
	 * @var SS_List
	 */
	protected $searchList;

	/**
	 * Define column names which should be included in the search.
	 * By default, they're searched with a {@link StartsWithFilter}.
	 * To define custom filters, use the same notation as {@link DataList->filter()},
	 * e.g. "Name:EndsWith".
	 * 
	 * If multiple fields are provided, the filtering is performed non-exclusive.
	 * If no fields are provided, tries to auto-detect fields from
	 * {@link DataObject->searchableFields()}.
	 * 
	 * The fields support "dot-notation" for relationships, e.g.
	 * a entry called "Team.Name" will search through the names of
	 * a "Team" relationship.
	 *
	 * @example
	 *  array(
	 *  	'Name',
	 *  	'Email:StartsWith',
	 *  	'Team.Name'
	 *  )
	 *
	 * @var Array
	 */
	protected $searchFields = array();

	/**
	 * @var string SSViewer template to render the results presentation
	 */
	protected $resultsFormat = '$Title';

	/**
	 * @var String Text shown on the search field, instructing what to search for.
	 */
	protected $placeholderText;

	/**
	 * @var int
	 */
	protected $resultsLimit = 20;

	/**
	 *
	 * @param array $searchFields Which fields on the object in the list should be searched
	 */
	public function __construct($targetFragment = 'before', $searchFields = null) {
		$this->targetFragment = $targetFragment;
		$this->searchFields = (array)$searchFields;
	}
	
	/**
	 * 
	 * @param GridField $gridField
	 * @return string - HTML
	 */
	public function getHTMLFragments($gridField) {
		$searchState = $gridField->State->GridFieldSearchRelation;
		$dataClass = $gridField->getList()->dataClass();
		
		$forTemplate = new ArrayData(array());
		$forTemplate->Fields = new ArrayList();

		$searchFields = ($this->getSearchFields())
			? $this->getSearchFields()
			: $this->scaffoldSearchFields($dataClass);
		
		$value = $this->findSingleEntry($gridField, $searchFields, $searchState, $dataClass);

		$searchField = new TextField('gridfield_relationsearch',
			_t('GridField.RelationSearch', "Relation search"), $value);
		$searchField->setAttribute('data-search-url', Controller::join_links($gridField->Link('search')));
		$searchField->setAttribute('placeholder', $this->getPlaceholderText($dataClass));
		$searchField->addExtraClass('relation-search no-change-track');
		
		$findAction = new GridField_FormAction($gridField, 'gridfield_relationfind',
			_t('GridField.Find', "Find"), 'find', 'find');
		$findAction->setAttribute('data-icon', 'relationfind');

		$addAction = new GridField_FormAction($gridField, 'gridfield_relationadd',
			_t('GridField.LinkExisting', "Link Existing"), 'addto', 'addto');
		$addAction->setAttribute('data-icon', 'chain--plus');

		// If an object is not found, disable the action
		if(!is_int($gridField->State->GridFieldAddRelation)) {
			$addAction->setReadonly(true);
		}
		
		$forTemplate->Fields->push($searchField);
		$forTemplate->Fields->push($findAction);
		$forTemplate->Fields->push($addAction);
		
		return array(
			$this->targetFragment => $forTemplate->renderWith($this->itemClass)
		);
	}
	
	/**
	 *
	 * @param GridField $gridField
	 * @return array
	 */
	public function getActions($gridField) {
		return array('addto', 'find');
	}

	/**
	 * Manipulate the state to either add a new relation, or doing a small search
	 * 
	 * @param GridField $gridField
	 * @param string $actionName
	 * @param string $arguments
	 * @param string $data
	 * @return string
	 */
	public function handleAction(GridField $gridField, $actionName, $arguments, $data) {
		switch($actionName) {
			case 'addto':
				if(isset($data['relationID']) && $data['relationID']){
					$gridField->State->GridFieldAddRelation = $data['relationID'];
				}
				$gridField->State->GridFieldSearchRelation = '';
				break;
			case 'find' && isset($data['autosuggest_search']):
				$gridField->State->GridFieldSearchRelation = $data['autosuggest_search'];
				break;
		}
	}
	
	/**
	 * If an object ID is set, add the object to the list
	 *
	 * @param GridField $gridField
	 * @param SS_List $dataList
	 * @return SS_List 
	 */
	public function getManipulatedData(GridField $gridField, SS_List $dataList) {
		if(!$gridField->State->GridFieldAddRelation) {
			return $dataList;
		}
		$objectID = Convert::raw2sql($gridField->State->GridFieldAddRelation);
		if($objectID) {
			$object = DataObject::get_by_id($dataList->dataclass(), $objectID);
			if($object) {
				$dataList->add($object);
			}
		}
		$gridField->State->GridFieldAddRelation = null;
		return $dataList;
	}
	
	/**
	 *
	 * @param GridField $gridField
	 * @return array
	 */
	public function getURLHandlers($gridField) {
		return array(
			'search' => 'doSearch',
		);
	}
	
	/**
	 * Returns a json array of a search results that can be used by for example Jquery.ui.autosuggestion
	 *
	 * @param GridField $gridField
	 * @param SS_HTTPRequest $request 
	 */
	public function doSearch($gridField, $request) {
		$dataClass = $gridField->getList()->dataClass();
		$allList = $this->searchList ? $this->searchList : DataList::create($dataClass);
		
		$searchFields = ($this->getSearchFields())
			? $this->getSearchFields()
			: $this->scaffoldSearchFields($dataClass);
		if(!$searchFields) {
			throw new LogicException(
				sprintf('GridFieldAddExistingAutocompleter: No searchable fields could be found for class "%s"',
				$dataClass));
		}

		$params = array();
		foreach($searchFields as $searchField) {
			$name = (strpos($searchField, ':') !== FALSE) ? $searchField : "$searchField:StartsWith";
			$params[$name] = $request->getVar('gridfield_relationsearch');
		}
		$results = $allList
			->subtract($gridField->getList())
			->filterAny($params)
			->sort(strtok($searchFields[0], ':'), 'ASC')
			->limit($this->getResultsLimit());

		$json = array();
		foreach($results as $result) {
			$json[$result->ID] = SSViewer::fromString($this->resultsFormat)->process($result);
		}
		return Convert::array2json($json);
	}

	/**
	 * @param String
	 */
	public function setResultsFormat($format) {
		$this->resultsFormat = $format;
		return $this;
	}

	/**
	 * @return String
	 */
	public function getResultsFormat() {
		return $this->resultsFormat;
	}

	/**
	 * Sets the base list instance which will be used for the autocomplete
	 * search.
	 *
	 * @param SS_List $list
	 */
	public function setSearchList(SS_List $list) {
		$this->searchList = $list;
	}

	/**
	 * @param Array
	 */
	public function setSearchFields($fields) {
		$this->searchFields = $fields;
		return $this;
	}

	/**
	 * @return Array
	 */
	public function getSearchFields() {
		return $this->searchFields;
	}

	/**
	 * Detect searchable fields and searchable relations.
	 * Falls back to {@link DataObject->summaryFields()} if
	 * no custom search fields are defined.
	 * 
	 * @param  String the class name
	 * @return Array|null names of the searchable fields
	 */
	public function scaffoldSearchFields($dataClass) {
		$obj = singleton($dataClass);
		$fields = null;
		if($fieldSpecs = $obj->searchableFields()) {
			$customSearchableFields = $obj->stat('searchable_fields');
			foreach($fieldSpecs as $name => $spec) {
				if(is_array($spec) && array_key_exists('filter', $spec)) {
					// The searchableFields() spec defaults to PartialMatch,
					// so we need to check the original setting.
					// If the field is defined $searchable_fields = array('MyField'),
					// then default to StartsWith filter, which makes more sense in this context.
					if(!$customSearchableFields || array_search($name, $customSearchableFields)) {
						$filter = 'StartsWith';
					} else {
						$filter = preg_replace('/Filter$/', '', $spec['filter']);
					}
					$fields[] = "{$name}:{$filter}";
				} else {
					$fields[] = $name;
				}
			}
		}
		if (is_null($fields)) {
			if ($obj->hasDatabaseField('Title')) {
				$fields = array('Title');
			} elseif ($obj->hasDatabaseField('Name')) {
				$fields = array('Name');
			}
		}

		return $fields;
	}

	/**
	 * @param String The class of the object being searched for
	 * @return String
	 */
	public function getPlaceholderText($dataClass) {
		$searchFields = ($this->getSearchFields())
			? $this->getSearchFields()
			: $this->scaffoldSearchFields($dataClass);

		if($this->placeholderText) {
			return $this->placeholderText;
		} else {
			$labels = array();
			if($searchFields) foreach($searchFields as $searchField) {
				$label = singleton($dataClass)->fieldLabel($searchField);
				if($label) $labels[] = $label;
			}
			if($labels) {
				return _t(
					'GridField.PlaceHolderWithLabels', 
					'Find {type} by {name}',  
					array('type' => singleton($dataClass)->plural_name(), 'name' => implode(', ', $labels))
				);
			} else {
				return _t(
					'GridField.PlaceHolder', 'Find {type}',
					array('type' => singleton($dataClass)->plural_name())
				);
			}
		}
	}

	/**
	 * @param String
	 */
	public function setPlaceholderText($text) {
		$this->placeholderText = $text;
	}

	/**
	 * Gets the maximum number of autocomplete results to display.
	 *
	 * @return int
	 */
	public function getResultsLimit() {
		return $this->resultsLimit;
	}

	/**
	 * @param int $limit
	 */
	public function setResultsLimit($limit) {
		$this->resultsLimit = $limit;
	}

	/**
	 * This will provide a StartsWith search that only returns a value if we are
	 * matching ONE object only. We wouldn't want to attach used any object to
	 * the list.
	 * 
	 * @param GridField $gridField
	 * @param string $field
	 * @param string $searchTerm
	 * @param string $dataclass
	 * @return string 
	 */
	protected function findSingleEntry($gridField, $field, $searchTerm, $dataclass) {
		$fullList = DataList::create($dataclass);
		$searchTerm = Convert::raw2sql($searchTerm);
		if(!$searchTerm) {
			return;
		}
		$existingList = clone $gridField->getList();
		$searchResults = $fullList->subtract($existingList->limit(0))->filter($field.':StartsWith', $searchTerm);
		
		// If more than one, skip
		if($searchResults->count() != 1) {
			return '';
		}
		
		$gridField->State->GridFieldAddRelation = $searchResults->first()->ID;
		return $searchResults->first()->$field;
	}
}