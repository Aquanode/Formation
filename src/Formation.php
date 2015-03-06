<?php namespace Regulus\Formation;

/*----------------------------------------------------------------------------------------------------------
	Formation
		A powerful form creation and form data saving composer package for Laravel 5.

		created by Cody Jassman
		version 0.9.9
		last updated on March 5, 2014
----------------------------------------------------------------------------------------------------------*/

use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\Input;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;

use Regulus\TetraText\Facade as Format;

class Formation {

	/**
	 * The HTML builder instance.
	 *
	 * @var \Illuminate\Html\HtmlBuilder
	 */
	protected $html;

	/**
	 * The URL generator instance.
	 *
	 * @var \Illuminate\Routing\UrlGenerator  $url
	 */
	protected $url;

	/**
	 * The CSRF token used by the form builder.
	 *
	 * @var string
	 */
	protected $csrfToken;

	/**
	 * The session store implementation.
	 *
	 * @var \Illuminate\Session\Store
	 */
	protected $session;

	/**
	 * The current model instance for the form.
	 *
	 * @var mixed
	 */
	protected $model;

	/**
	 * The reserved form open attributes.
	 *
	 * @var array
	 */
	protected $reserved = ['method', 'url', 'route', 'action', 'files'];

	/**
	 * The form methods that should be spoofed, in uppercase.
	 *
	 * @var array
	 */
	protected $spoofedMethods = ['DELETE', 'PATCH', 'PUT'];

	/**
	 * The default values for form fields.
	 *
	 * @var array
	 */
	protected $defaults = [];

	/**
	 * The labels for form fields.
	 *
	 * @var array
	 */
	protected $labels = [];

	/**
	 * The access keys for form fields.
	 *
	 * @var array
	 */
	protected $accessKeys = [];

	/**
	 * The validation rules (routed through Formation's validation() method to Validator library to allow
	 * automatic addition of error classes to labels and fields).
	 *
	 * @var array
	 */
	protected $validation = [];

	/**
	 * The form fields to be validated.
	 *
	 * @var array
	 */
	protected $validationFields = [];

	/**
	 * The form values array or object.
	 *
	 * @var array
	 */
	protected $values = [];

	/**
	 * The form errors.
	 *
	 * @var array
	 */
	protected $errors = [];

	/**
	 * Whether form fields are being reset to their default values rather than the POSTed values.
	 *
	 * @var bool
	 */
	protected $reset = false;

	/**
	 * The request spoofer.
	 *
	 * @var string
	 */
	protected $spoofer = '_method';

	/**
	 * Cache application encoding locally to save expensive calls to config::get().
	 *
	 * @var string
	 */
	protected $encoding = null;

	/**
	 * Create a new Formation instance.
	 *
	 * @param  \Illuminate\Html\HtmlBuilder      $html
	 * @param  \Illuminate\Routing\UrlGenerator  $url
	 * @param  string  $csrfToken
	 * @return void
	 */
	public function __construct(UrlGenerator $url, $csrfToken)
	{
		$this->url       = $url;
		$this->csrfToken = $csrfToken;
	}

	/**
	 * Returns the POST data.
	 *
	 * @return mixed
	 */
	public function post()
	{
		if (Input::old())
			return Input::old();

		return Input::all();
	}

	/**
	 * Sets the default values for the form.
	 *
	 * @param  array    $defaults
	 * @param  array    $relations
	 * @param  mixed    $prefix
	 * @return array
	 */
	public function setDefaults($defaults = [], $relations = [], $prefix = null)
	{
		//check if relations is an associative array
		$associative = (bool) count(array_filter(array_keys((array) $relations), 'is_string'));

		//prepare prefix
		if (is_string($prefix) && $prefix != "")
			$prefix .= ".";
		else
			$prefix = "";

		//format default values for times
		$defaults = $this->formatDefaults($defaults);

		//set defaults array
		$defaultsArray = $this->defaults;

		//turn Eloquent collection into an array
		if (isset($defaults) && isset($defaults->incrementing) && isset($defaults->timestamps))
			$defaultsFormatted = $defaults->toArray();
		else
			$defaultsFormatted = $defaults;

		foreach ($defaultsFormatted as $field => $value)
		{
			$addValue = true;

			if ((is_array($value) || is_object($value)) && ! (int) $field)
				$addValue = false;

			//decode JSON array
			if (is_string($value) && substr($value, 0, 2) == "[\"" && substr($value, -2) == "\"]")
				$value = json_decode($value);

			//decode JSON object
			if (is_string($value) && substr($value, 0, 1) == "{" && substr($value, -1) == "}")
			{
				$value    = json_decode($value, true);
				$addValue = false;

				if ($prefix != "")
					$prefixForArray = $prefix.'.'.$field;
				else
					$prefixForArray = $field;

				$defaultsArray = $this->addArrayToDefaults($value, $prefixForArray, $defaultsArray);
			}

			if ($addValue)
				$defaultsArray[$prefix.$field] = $value;
		}

		//the suffix that formatted values will have if Formation's BaseModel is used as the model
		$formattedSuffix = $this->getFormattedFieldSuffix();

		//add relations data to defaults array if it is set
		if (!empty($relations))
		{
			foreach ($relations as $key => $relation)
			{
				$relationNumberField = null;
				$relationField       = null;

				if ($associative)
				{
					//check to see if a different number is being chosen from a field
					if (preg_match('/\[number:([A-Za-z\.\_]*)\]/', $relation, $match))
					{
						$relationNumberField = explode('.', $match[1]);
					}
					else //otherwise, a specific field is being selected
					{
						if (is_string($relation))
							$relationField = $relation;
					}

					$relation = $key;
				}

				if (count($defaults->{$relation}))
				{
					$i = 1;

					foreach ($defaults->{$relation} as $item)
					{
						$number = $i;

						if (!is_null($relationNumberField))
						{
							if (count($relationNumberField) == 1 && isset($item->{$relationNumberField[0]}))
								$number = $item->{$relationNumberField[0]};

							if (count($relationNumberField) == 2 && isset($item->{$relationNumberField[0]}) && isset($item->{$relationNumberField[0]}->{$relationNumberField[1]}))
								$number = $item->{$relationNumberField[0]}->{$relationNumberField[1]};

							if (count($relationNumberField) == 3 && isset($item->{$relationNumberField[0]}) && isset($item->{$relationNumberField[0]}->{$relationNumberField[1]})
							&& isset($item->{$relationNumberField[0]}->{$relationNumberField[1]}->{$relationNumberField[2]}))
								$number = $item->{$relationNumberField[0]}->{$relationNumberField[1]}->{$relationNumberField[2]};
						}

						$item = $item->toArray();

						$itemPrefix = $prefix.($this->camelCaseToUnderscore($relation));

						foreach ($item as $field => $value)
						{
							if (!$relationField || $relationField == $field || ($relationField && $field == "pivot"))
							{
								if ($field == "pivot") {
									foreach ($value as $pivotField => $pivotValue)
									{
										if ($relationField) {
											if ($relationField == $pivotField)
												$defaultsArray[$itemPrefix.'.pivot.'][] = $pivotValue;
										} else {
											if (substr($field, -(strlen($formattedSuffix))) == $formattedSuffix)
												$fieldName = str_replace($formattedSuffix, '', $pivotField);
											else
												$fieldName = $pivotField;

											$defaultsArray[$itemPrefix.'.'.$number.'.pivot.'.$fieldName] = $pivotValue;
										}
									}
								} else {
									$addValue = true;

									if (substr($field, -(strlen($formattedSuffix))) == $formattedSuffix)
										$fieldName = str_replace($formattedSuffix, '', $field);
									else
										$fieldName = $field;

									//decode JSON array
									if (is_string($value) && substr($value, 0, 2) == "[\"" && substr($value, -2) == "\"]")
										$value = json_decode($value);

									//decode JSON object
									if (is_string($value) && substr($value, 0, 1) == "{" && substr($value, -1) == "}")
									{
										$value    = json_decode($value, true);
										$addValue = false;

										$prefixForArray = $itemPrefix.'.'.$number.'.'.$fieldName;

										$defaultsArray = $this->addArrayToDefaults($value, $prefixForArray, $defaultsArray);
									}

									if ($addValue)
									{
										if ($relationField)
											$defaultsArray[$itemPrefix][] = $value;
										else
											$defaultsArray[$itemPrefix.'.'.$number.'.'.$fieldName] = $value;
									}
								}
							}
						}

						$i ++;
					}
				}
			}
		}

		$this->defaults = $defaultsArray;

		return $this->defaults;
	}

	/**
	 * Turn multidimensional array into dot notation for defaults array.
	 *
	 * @param  array    $array
	 * @param  mixed    $prefix
	 * @param  array    $defaultsArray
	 * @return array
	 */
	private function addArrayToDefaults($array, $prefix = null, $defaultsArray = [])
	{
		if (is_null($prefix))
			$prefix = "";

		$rootPrefix = $prefix;

		foreach ($array as $field => $value)
		{
			if ($rootPrefix != "")
				$prefix = $rootPrefix.'.'.$field;
			else
				$prefix = $field;

			if (is_array($value))
			{
				$associative = array_keys($value) !== range(0, count($value) - 1);

				if ($associative)
					$defaultsArray = $this->addArrayToDefaults($value, $prefix, $defaultsArray);
				else
					$defaultsArray[$prefix] = $value;
			} else {
				$defaultsArray[$prefix] = $value;
			}
		}

		return $defaultsArray;
	}

	/**
	 * Format default values for times.
	 *
	 * @param  array    $defaults
	 * @return array
	 */
	private function formatDefaults($defaults = [])
	{
		foreach ($defaults as $field => $value) {
			$fieldArray = explode('.', $field);

			//divide any field that starts with "time" into "hour", "minutes", and "meridiem" fields
			if (substr(end($fieldArray), 0, 4) == "time")
			{
				$valueArray = explode(':', $value);

				if (count($valueArray) >= 2)
				{
					$defaults[$field.'_hour']     = $valueArray[0];
					$defaults[$field.'_minutes']  = $valueArray[1];
					$defaults[$field.'_meridiem'] = "am";

					if ($valueArray[0] >= 12)
					{
						$defaults[$field.'_hour']     -= 12;
						$defaults[$field.'_meridiem']  = "pm";
					}
				}
			}
		}

		return $defaults;
	}

	/**
	 * Get formatted field suffix.
	 *
	 * @return string
	 */
	public function getFormattedFieldSuffix()
	{
		return "_formatted";
	}

	/**
	 * Get an array of all values. Turns values with dot notation names back into proper arrays.
	 *
	 * @param  mixed    $name
	 * @param  boolean  $object
	 * @param  boolean  $defaults
	 * @return mixed
	 */
	public function getValuesArray($name = null, $object = false, $defaults = false) {
		$result = [];

		if (!$defaults && (Input::all() || Input::old())) {
			if (Input::all())
				$values = Input::all();
			else
				$values = Input::old();

			$result = $values;
		} else {
			foreach ($this->defaults as $field => $value)
			{
				$s = explode('.', $field);

				if (!is_null($value))
				{
					switch (count($s)) {
						case 1:	$result[$s[0]] = $value; break;
						case 2:	$result[$s[0]][$s[1]] = $value; break;
						case 3:	$result[$s[0]][$s[1]][$s[2]] = $value; break;
						case 4:	$result[$s[0]][$s[1]][$s[2]][$s[3]] = $value; break;
						case 5:	$result[$s[0]][$s[1]][$s[2]][$s[3]][$s[4]] = $value; break;
						case 6:	$result[$s[0]][$s[1]][$s[2]][$s[3]][$s[4]][$s[5]] = $value; break;
						case 7:	$result[$s[0]][$s[1]][$s[2]][$s[3]][$s[4]][$s[5]][$s[6]] = $value; break;
					}
				}
			}
		}

		if (!is_null($name))
		{
			$names = explode('.', $name);

			switch (count($names))
			{
				case 1:
					if (isset($result[$names[0]]))
						$result = $result[$names[0]];
					else
						$result = [];

					break;

				case 2:
					if (isset($result[$names[0]][$names[1]]))
						$result = $result[$names[0]][$names[1]];
					else
						$result = [];

					break;

				case 3:
					if (isset($result[$names[0]][$names[1]][$names[2]]))
						$result = $result[$names[0]][$names[1]][$names[2]];
					else
						$result = [];

					break;

				case 4:
					if (isset($result[$names[0]][$names[1]][$names[2]][$names[3]]))
						$result = $result[$names[0]][$names[1]][$names[2]][$names[3]];
					else
						$result = [];

					break;

				case 4:
					if (isset($result[$names[0]][$names[1]][$names[2]][$names[3]][$names[4]]))
						$result = $result[$names[0]][$names[1]][$names[2]][$names[3]][$names[4]];
					else
						$result = [];

					break;
			}
		}

		if ($object)
			$result = json_decode(json_encode($result));

		$this->values = $result;

		return $result;
	}

	/**
	 * Get an object of all values.
	 *
	 * @param  mixed    $name
	 * @return object
	 */
	public function getValuesObject($name = null)
	{
		return $this->getValuesArray($name, true, false);
	}

	/**
	 * Get a JSON string of all values.
	 *
	 * @param  mixed    $name
	 * @return object
	 */
	public function getJsonValues($name = null)
	{
		return addslashes(json_encode($this->getValuesArray($name)));
	}

	/**
	 * Get an array of all default values. Turns values with dot notation names back into proper arrays.
	 *
	 * @param  mixed    $name
	 * @return array
	 */
	public function getDefaultsArray($name = null)
	{
		return $this->getValuesArray($name, false, true);
	}

	/**
	 * Get an object of all default values.
	 *
	 * @param  mixed    $name
	 * @return object
	 */
	public function getDefaultsObject($name = null)
	{
		return $this->getValuesArray($name, true, true);
	}

	/**
	 * Get a value from an array if it exists.
	 *
	 * @param  string   $field
	 * @param  array    $values
	 * @return string
	 */
	public function getValueFromArray($field, $values = null)
	{
		if (isset($values[$field]))
			return $values[$field];

		return "";
	}

	/**
	 * Get a value from an object if it exists.
	 *
	 * @param  string   $field
	 * @param  object   $values
	 * @return string
	 */
	public function getValueFromObject($field, $values = null)
	{
		$fieldKeys = explode('.', $field);

		if (is_null($values))
			$values = $this->values;

		if (!is_object($values))
			$values = json_decode(json_encode($values));

		if (count($fieldKeys) == 1) {
			if (isset($values->{$fieldKeys[0]}))
				return $values->{$fieldKeys[0]};
		} else if (count($fieldKeys) == 2) {
			if (isset($values->{$fieldKeys[0]}->{$fieldKeys[1]}))
				return $values->{$fieldKeys[0]}->{$fieldKeys[1]};
		} else if (count($fieldKeys) == 3) {
			if (isset($values->{$fieldKeys[0]}->{$fieldKeys[1]}->{$fieldKeys[2]}))
				return $values->{$fieldKeys[0]}->{$fieldKeys[1]}->{$fieldKeys[2]};
		}

		return "";
	}

	/**
	 * Reset form field values back to defaults and ignores POSTed values.
	 *
	 * @param  array    $defaults
	 * @return void
	 */
	public function resetDefaults($defaults = [])
	{
		if (!empty($defaults)) $this->setDefaults($defaults); //if new defaults are set, pass them to $this->defaults
		$this->reset = true;
	}

	/**
	 * Assign labels to form fields.
	 *
	 * @param  array    $labels
	 * @return void
	 */
	public function setLabels($labels = [])
	{
		if (is_object($labels))
			$labels = (array) $labels;

		$this->labels = array_merge($this->labels, $labels);
	}

	/**
	 * Get the labels for form fields.
	 *
	 * @return array
	 */
	public function getLabels()
	{
		return $this->labels;
	}

	/**
	 * Route Validator validation rules through Formation to allow Formation
	 * to automatically add error classes to labels and fields.
	 *
	 * @param  array    $rules
	 * @param  mixed    $prefix
	 * @return array
	 */
	public function setValidationRules($rules = [], $prefix = null)
	{
		$rulesFormatted = [];
		foreach ($rules as $name => $rulesItem) {
			if (!is_null($prefix))
				$name = $prefix.'.'.$name;

			$this->validationFields[] = $name;

			$rulesArray = explode('.', $name);
			$last = $rulesArray[(count($rulesArray) - 1)];
			if (count($rulesArray) < 2) {
				$rulesFormatted['root'][$last] = $rulesItem;
			} else {
				$rulesFormatted[str_replace('.'.$last, '', $name)][$last] = $rulesItem;
			}
		}

		foreach ($rulesFormatted as $name => $rules) {
			if ($name == "root") {
				$this->validation['root'] = Validator::make(Input::all(), $rules);
			} else {
				$data = Input::get($name);
				if (is_null($data))
					$data = [];

				$this->validation[$name] = Validator::make($data, $rules);
			}
		}

		return $this->validation;
	}

	/**
	 * Check if one or all Validator instances are valid.
	 *
	 * @param  string   $index
	 * @return bool
	 */
	public function validated($index = null)
	{
		//if index is null, cycle through all Validator instances
		if (is_null($index)) {
			foreach ($this->validation as $fieldName => $validation) {
				if ($validation->fails()) return false;
			}
		} else {
			if (substr($index, -1) == ".") { //index ends in "."; validate all fields that start with that index
				foreach ($this->validation as $fieldName => $validation) {
					if (substr($fieldName, 0, strlen($index)) == $index) {
						if ($validation->fails()) return false;
					}
				}
			} else {
				if (isset($this->validation[$index])) {
					if ($this->validation[$index]->fails()) return false;
				} else {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Set up whole form with one big array
	 *
	 * @param  array    $form
	 * @return array
	 */
	public function setup($form = [])
	{
		$labels   = [];
		$rules    = [];
		$defaults = [];

		if (is_object($form))
			$form = (array) $form;

		foreach ($form as $name => $field)
		{
			if (is_object($field))
				$field = (array) $field;

			if (isset($field[0]) && !is_null($field[0]) && $field[0] != "") $labels[$name]   = $field[0];
			if (isset($field[1]) && !is_null($field[1]) && $field[1] != "") $rules[$name]    = $field[1];
			if (isset($field[2]) && !is_null($field[2]) && $field[2] != "") $defaults[$name] = $field[2];
		}

		$this->setLabels($labels);
		$this->setValidationRules($rules);
		$this->setDefaults($defaults);

		return $this->validation;
	}

	/**
	 * Determine the appropriate request method to use for a form.
	 *
	 * @param  string  $method
	 * @return string
	 */
	protected function method($method = 'POST')
	{
		return $method !== "GET" ? "POST" : $method;
	}

	/**
	 * Determine the appropriate request method for a resource controller form.
	 *
	 * @param  mixed   $route
	 * @return string
	 */
	public function methodResource($route = null)
	{
		$route  = $this->route($route);
		$method = "POST";

		if (substr($route[0], -5) == ".edit")
			$method = "PUT";

		return $method;
	}

	/**
	 * Determine the appropriate action parameter to use for a form.
	 *
	 * If no action is specified, the current request URI will be used.
	 *
	 * @param  string   $action
	 * @param  bool     $https
	 * @return string
	 */
	protected function route($route = null)
	{
		if (!is_null($route))
			return $route;

		return array_merge(
			[Route::currentRouteName()],
			array_values(Route::getCurrentRoute()->parameters())
		);
	}

	/**
	 * Open up a new HTML form.
	 *
	 * @param  array    $options
	 * @return string
	 */
	public function open(array $options = [])
	{
		$method = array_get($options, 'method', 'post');

		// We need to extract the proper method from the attributes. If the method is
		// something other than GET or POST we'll use POST since we will spoof the
		// actual method since forms don't support the reserved methods in HTML.
		$attributes['method'] = $this->getMethod($method);

		$attributes['action'] = $this->getAction($options);

		$attributes['accept-charset'] = 'UTF-8';

		// If the method is PUT, PATCH or DELETE we will need to add a spoofer hidden
		// field that will instruct the Symfony request to pretend the method is a
		// different method than it actually is, for convenience from the forms.
		$append = $this->getAppendage($method);

		if (isset($options['files']) && $options['files'])
		{
			$options['enctype'] = 'multipart/form-data';
		}

		// Finally we're ready to create the final form HTML field. We will attribute
		// format the array of attributes. We will also add on the appendage which
		// is used to spoof requests for this PUT, PATCH, etc. methods on forms.
		$attributes = array_merge(

			$attributes, array_except($options, $this->reserved)

		);

		// Finally, we will concatenate all of the attributes into a single string so
		// we can build out the final form open statement. We'll also append on an
		// extra value for the hidden _method field if it's needed for the form.
		$attributes = $this->html->attributes($attributes);

		return '<form'.$attributes.'>'."\n\n\t".$append;
	}

	/**
	 * Open an HTML form that automatically corrects the action for a resource controller.
	 *
	 * @param  mixed   $route
	 * @param  array   $attributes
	 * @return string
	 */
	public function openResource(array $attributes = [])
	{
		$route = $this->route();

		//set method based on action
		$method = $this->methodResource($route);

		$route[0] = str_replace('create', 'store', $route[0]);
		$route[0] = str_replace('edit', 'update', $route[0]);

		$options = array_merge([
			'route'  => $route,
			'method' => $method,
		], $attributes);

		return $this->open($options);
	}

	/**
	 * Create a new model based form builder.
	 *
	 * @param  mixed  $model
	 * @param  array  $options
	 * @return string
	 */
	public function model($model, array $options = array())
	{
		$this->model = $model;

		return $this->open($options);
	}

	/**
	 * Set the model instance on the form builder.
	 *
	 * @param  mixed  $model
	 * @return void
	 */
	public function setModel($model)
	{
		$this->model = $model;
	}

	/**
	 * Close the current form.
	 *
	 * @return string
	 */
	public function close()
	{
		$this->labels = array();

		$this->model = null;

		return '</form>';
	}

	/**
	 * Generate a hidden field with the current CSRF token.
	 *
	 * @return string
	 */
	public function token()
	{
		return $this->hidden('_token', ['value' => $this->csrfToken]);
	}

	/**
	 * Get the value of the form or of a form field array.
	 *
	 * @param  string  $name
	 * @param  string  $type
	 * @return mixed
	 */
	public function values($name = null)
	{
		if (is_string($name))
			$name = str_replace('(', '', str_replace(')', '', $name));

		if ($_POST && !$this->reset) {
			return Input::get($name);
		} else if (Input::old($name) && !$this->reset) {
			return Input::old($name);
		} else {
			return $this->getDefaultsArray($name);
		}
	}

	/**
	 * Get the value of the form field. If no POST data exists or reinitialize() has been called, default value
	 * will be used. Otherwise, POST value will be used. Using "checkbox" type ensures a boolean return value.
	 *
	 * @param  string  $name
	 * @param  string  $type
	 * @return mixed
	 */
	public function value($name, $type = 'standard')
	{
		$name  = str_replace('(', '', str_replace(')', '', $name));
		$value = "";

		if (isset($this->defaults[$name]))
			$value = $this->defaults[$name];

		if ($_POST && !$this->reset)
			$value = Input::get($name);

		if (!is_null(Input::old($name)) && !$this->reset)
			$value = Input::old($name);

		if ($type == "checkbox")
			$value = (bool) $value;

		return $value;
	}

	/**
	 * Get the time value from 3 individual fields created from the selectTime() method.
	 *
	 * @param  string  $name
	 * @param  string  $type
	 * @return mixed
	 */
	public function valueTime($name)
	{
		if (substr($name, -1) != "_") $name .= "_";

		$hour     = Input::get($name.'hour');
		$minutes  = Input::get($name.'minutes');
		$meridiem = Input::get($name.'meridiem');

		if ($hour == 12)
			$hour = 0;

		if ($meridiem == "pm")
			$hour += 12;

		return sprintf('%02d', $hour).':'.sprintf('%02d', $minutes).':00';
	}

	/**
	 * Add values to a data object or array.
	 *
	 * @param  mixed   $values
	 * @param  array   $fields
	 * @return mixed
	 */
	public function addValues($data = [], $fields = [])
	{
		$associative = (bool) count(array_filter(array_keys((array) $fields), 'is_string'));

		if ($associative) {
			foreach ($fields as $field => $config) {
				$add = true;

				if (is_bool($config) || $config == "text") {
					$value = trim($this->value($field));

					if (!$config)
						$add = false;
				} else if (is_array($config)) {
					$value = trim($this->value($field));

					if (!in_array($value, $config))
						$add = false;
				} else if ($config == "checkbox") {
					$value = $this->value($field, 'checkbox');
				}

				if ($add) {
					if (is_object($data))
						$data->{$field} = $value;
					else
						$data[$field]   = $value;
				}
			}
		} else {
			foreach ($fields as $field) {
				$value = trim($this->value($field));

				if (is_object($data))
					$data->{$field} = $value;
				else
					$data[$field]   = $value;
			}
		}

		return $data;
	}

	/**
	 * Add checkbox values to a data object or array.
	 *
	 * @param  mixed   $values
	 * @param  array   $checkboxes
	 * @return mixed
	 */
	public function addCheckboxValues($data = [], $checkboxes = [])
	{
		foreach ($checkboxes as $checkbox) {
			$value = $this->value($checkbox, 'checkbox');

			if (is_object($data))
				$data->{$checkbox} = $value;
			else
				$data[$checkbox]   = $value;
		}

		return $data;
	}

	/**
	 * Check whether a checkbox is checked.
	 *
	 * @param  string  $name
	 * @return boolean
	 */
	public function checked($name)
	{
		return $this->value($name, 'checkbox');
	}

	/**
	 * Format array named form fields from strings with period notation for arrays ("data.id" = "data[id]")
	 *
	 * @param  string  $name
	 * @return string
	 */
	protected function name($name)
	{
		//remove index number from between round brackets
		if (preg_match("/\((.*)\)/i", $name, $match))
			$name = str_replace($match[0], '', $name);

		$nameArray = explode('.', $name);

		if (count($nameArray) < 2)
			return $name;

		$nameFormatted = $nameArray[0];
		for ($n = 1; $n < count($nameArray); $n++) {
			$nameFormatted .= '['.$nameArray[$n].']';
		}

		return $nameFormatted;
	}

	/**
	 * Create an HTML label element.
	 *
	 * <code>
	 *		// Create a label for the "email" input element
	 *		echo Form::label('email', 'Email Address');
	 * </code>
	 *
	 * @param  string  $name
	 * @param  string  $value
	 * @param  array   $attributes
	 * @param  boolean $save
	 * @return string
	 */
	public function label($name = null, $label = null, $attributes = [], $save = true)
	{
		$attributes = $this->addErrorClass($name, $attributes);

		if (!is_null($name) && $name != "") {
			if (is_null($label))
				$label = $this->nameToLabel($name);
		} else {
			if (is_null($label))
				$label = "";
		}

		//save label in labels array if a label string contains any characters and $save is true
		if ($label != "" && $save)
			$this->labels[$name] = $label;

		//get ID of field for label's "for" attribute
		if (!isset($attributes['for'])) {
			$id = $this->id($name);
			$attributes['for'] = $id;
		}

		//add label suffix
		$suffix = config('form.label.suffix');
		if ($suffix != "" && (!isset($attributes['suffix']) || $attributes['suffix']))
			$label .= $suffix;

		if (isset($attributes['suffix']))
			unset($attributes['suffix']);

		//add tooltip and tooltip attributes if necessary
		if (config('form.error.type_label_tooltip')) {
			$errorMessage = $this->errorMessage($name);

			if ($errorMessage)
			{
				$addAttributes = config('form.error.type_label_attributes');

				foreach ($addAttributes as $attribute => $attributeValue)
				{
					$attribute = str_replace('_', '-', $attribute);

					if (isset($attributes[$attribute]))
						$attributes[$attribute] .= ' '.$attributeValue;
					else
						$attributes[$attribute] = $attributeValue;
				}

				//set tooltip error message
				$attributes['title'] = str_replace('"', '&quot;', $errorMessage);
			}
		}

		//if any "{" characters are used, do not add "access" class for accesskey; Handlebars.js may be being used in field name or label
		if (preg_match('/\{/', $name))
			$attributes['accesskey'] = false;

		//also do not add accesskey depiction if label already contains HTML tags or HTML special characters
		if ($label != strip_tags($label) || $label != $this->entities($label))
			$attributes['accesskey'] = false;
		else
			$label = $this->entities($label); //since there is no HTML present in label, convert entities to HTML special characters

		//add accesskey
		$attributes = $this->addAccessKey($name, $label, $attributes, false);

		//add "control-label" class
		if (!isset($attributes['control-label-class']) || $attributes['control-label-class'])
		{
			if (isset($attributes['class']) && $attributes['class'] != "")
				$attributes['class'] .= ' '.config('form.label.class');
			else
				$attributes['class'] = config('form.label.class');
		}

		if (isset($attributes['control-label-class']))
			unset($attributes['control-label-class']);

		//add non-breakable space if label is empty
		if ($label == "")
			$label = "&nbsp;";

		if (is_array($attributes) && isset($attributes['accesskey']))
		{
			if (is_string($attributes['accesskey']))
			{
				$newLabel = preg_replace('/'.strtoupper($attributes['accesskey']).'/', '<span class="access">'.strtoupper($attributes['accesskey']).'</span>', $label, 1);

				if ($newLabel == $label) //if nothing changed with replace, try lowercase
					$newLabel = preg_replace('/'.$attributes['accesskey'].'/', '<span class="access">'.$attributes['accesskey'].'</span>', $label, 1);

				$label = $newLabel;
			}

			unset($attributes['accesskey']);
		}

		$attributes = $this->attributes($attributes);

		return '<label'.$attributes.'>'.$label.'</label>' . "\n";
	}

	/**
	 * Create an HTML label element.
	 *
	 * <code>
	 *		// Create a label for the "email" input element
	 *		echo Form::label('email', 'E-Mail Address');
	 * </code>
	 *
	 * @param  string  $name
	 * @return string
	 */
	protected function nameToLabel($name)
	{
		$nameArray = explode('.', $name);

		if (count($nameArray) < 2)
			$nameFormatted = str_replace('_', ' ', $name);
		else //if field is an array, create label from last array index
			$nameFormatted = str_replace('_', ' ', $nameArray[(count($nameArray) - 1)]);

		//convert icon code to markup
		if (preg_match('/\[ICON:(.*)\]/', $nameFormatted, $match))
			$nameFormatted = str_replace($match[0], '<span class="glyphicon glyphicon-'.str_replace(' ', '', $match[1]).'"></span>&nbsp; ', $nameFormatted);

		if ($nameFormatted == strip_tags($nameFormatted))
			$nameFormatted = ucwords($nameFormatted);

		return $nameFormatted;
	}

	/**
	 * Add an accesskey attribute to a field based on its name.
	 *
	 * @param  string  $name
	 * @param  string  $label
	 * @param  array   $attributes
	 * @param  boolean $returnLowercase
	 * @return array
	 */
	public function addAccessKey($name, $label = null, $attributes = [], $returnLowercase = true)
	{
		if (!isset($attributes['accesskey']) || (!is_string($attributes['accesskey']) && $attributes['accesskey'] === true))
		{
			$accessKey = false;

			if (is_null($label))
			{
				if (isset($this->labels[$name]))
					$label = $this->labels[$name];
				else
					$label = $this->nameToLabel($name);
			}

			$label = strtr($label, 'Ã Ã¡Ã¢Ã£Ã¤Ã§Ã¨Ã©ÃªÃ«Ã¬Ã­Ã®Ã¯Ã±Ã²Ã³Ã´ÃµÃ¶Ã¹ÃºÃ»Ã¼Ã½Ã¿Ã€ÃÃ‚ÃƒÃ„Ã‡ÃˆÃ‰ÃŠÃ‹ÃŒÃÃŽÃÃ‘Ã’Ã“Ã”Ã•Ã–Ã™ÃšÃ›ÃœÃ', 'aaaaaceeeeiiiinooooouuuuyyAAAAACEEEEIIIINOOOOOUUUUY');
			$ignoreCharacters = [' ', '/', '!', '@', '#', '$', '%', '^', '*', '(', ')', '-', '_', '+', '=', '\\', '~', '?', '{', '}', '[', ']', '.'];

			//first check to see if an accesskey is already set for this field
			foreach ($this->accessKeys as $character => $nameAccessKey) {
				if ($nameAccessKey == $name) $accessKey = $character;
			}

			//if no accesskey is set, loop through the field name's characters and set one
			for ($l = 0; $l < strlen($label); $l++) {
				if (!$accessKey)
				{
					$character = strtolower($label[$l]);

					if (!isset($this->accessKeys[$character]) && !in_array($character, $ignoreCharacters))
					{
						$this->accessKeys[$character] = $name;
						$accessKey = $character;
					}
				}
			}

			if ($accessKey) {
				$attributes['accesskey'] = $accessKey;

				if ($returnLowercase)
					$attributes['accesskey'] = strtolower($attributes['accesskey']);
			}
		} else {
			if ($attributes['accesskey'] === false) //allow ability to prevent accesskey by setting it to false
				unset($attributes['accesskey']);
		}
		return $attributes;
	}

	/**
	 * Determine the ID attribute for a form element.
	 *
	 * @param  string  $name
	 * @param  array   $attributes
	 * @return string
	 */
	protected function id($name, $attributes = [])
	{
		// If an ID has been explicitly specified in the attributes, we will
		// use that ID. Otherwise, we will look for an ID in the array of
		// label names so labels and their elements have the same ID.
		if (array_key_exists('id', $attributes)) {
			$id = $attributes['id'];
		} else {
			//replace array denoting periods and underscores with dashes
			$id = strtolower(str_replace('.', '-', str_replace('_', '-', str_replace(' ', '-', $name))));

			//add ID prefix
			$idPrefix = config('form.field.id_prefix');

			if (!is_null($idPrefix) && $idPrefix !== false && $idPrefix != "")
				$id = $idPrefix.$id;
		}

		//remove icon code
		if (preg_match('/\[ICON:(.*)\]/i', $id, $match))
			$id = str_replace($match[0], '', $id);

		//remove round brackets that are used to prevent index number from appearing in field name
		$id = str_replace('(', '', str_replace(')', '', $id));

		//remove quotation marks
		$id = str_replace('"', '', $id);

		//replace double dashes with single dash
		$id = str_replace('--', '-', $id);

		//remove end dash if one exists
		if (substr($id, -1) == "-")
			$id = substr($id, 0, (strlen($id) - 1));

		//unset ID attribute if ID is empty
		if (!$id || $id == "")
			unset($attributes['id']);

		return $id;
	}

	/**
	 * Automatically set the field class for a field.
	 *
	 * @param  string  $name
	 * @param  array   $attributes
	 * @param  string  $type
	 * @return array
	 */
	protected function setFieldClass($name, $attributes = [], $type = 'text')
	{
		if (!in_array($type, ['hidden', 'checkbox', 'radio']))
		{
			$defaultClass = config('form.field.class');
			if ($defaultClass != "")
			{
				if (isset($attributes['class']) && $attributes['class'] != "")
					$attributes['class'] .= ' '.$defaultClass;
				else
					$attributes['class'] = $defaultClass;
			}
		}

		$nameSegments = explode('.', $name);
		$fieldClass   = strtolower(str_replace('_', '-', str_replace(' ', '-', end($nameSegments))));

		//add "pivot" prefix to field name if it exists
		if (count($nameSegments) > 1 && $nameSegments[count($nameSegments) - 2] == "pivot")
			$fieldClass = $nameSegments[count($nameSegments) - 2]."-".$fieldClass;

		//remove icon code
		if (preg_match('/\[ICON:(.*)\]/i', $fieldClass, $match)) {
			$fieldClass = str_replace($match[0], '', $fieldClass);
		}

		//remove round brackets that are used to prevent index number from appearing in field name
		$fieldClass = str_replace('(', '', str_replace(')', '', $fieldClass));

		//remove end dash if one exists
		if (substr($fieldClass, -1) == "-")
			$fieldClass = substr($fieldClass, 0, (strlen($fieldClass) - 1));

		if ($fieldClass != "")
		{
			$fieldClass = "field-".$fieldClass;

			//replace double dashes with single dash
			$fieldClass = str_replace('--', '-', $fieldClass);

			if (isset($attributes['class']) && $attributes['class'] != "")
				$attributes['class'] .= ' '.$fieldClass;
			else
				$attributes['class'] = $fieldClass;
		}

		return $attributes;
	}

	/**
	 * Automatically set a "placeholder" attribute for a field.
	 *
	 * @param  string  $name
	 * @param  array   $attributes
	 * @return array
	 */
	protected function setFieldPlaceholder($name, $attributes = [])
	{
		$placeholder = config('form.field.auto_placeholder');

		if ($placeholder && !isset($attributes['placeholder'])) {
			$namePlaceholder = $name;
			if (isset($this->labels[$name]) && $this->labels[$name] != "") {
				$namePlaceholder = $this->labels[$name];
			} else {
				$namePlaceholder = $this->nameToLabel($name);
			}

			if (substr($namePlaceholder, -1) == ":")
				$namePlaceholder = substr($namePlaceholder, 0, (strlen($namePlaceholder) - 1));

			$attributes['placeholder'] = $namePlaceholder;
		}
		return $attributes;
	}

	/**
	 * Build a list of HTML attributes from an array.
	 *
	 * @param  array   $attributes
	 * @return string
	 */
	public function attributes($attributes)
	{
		$html = [];

		foreach ((array) $attributes as $key => $value)
		{
			// For numeric keys, we will assume that the key and the value are the
			// same, as this will convert HTML attributes such as "required" that
			// may be specified as required="required", etc.
			if (is_numeric($key)) $key = $value;

			if (!is_null($value))
			{
				$html[] = $key.'="'.$this->entities($value).'"';
			}
		}

		return (count($html) > 0) ? ' '.implode(' ', $html) : '';
	}

	/**
	 * Convert HTML characters to entities.
	 *
	 * The encoding specified in the application configuration file will be used.
	 *
	 * @param  string  $value
	 * @return string
	 */
	public function entities($value)
	{
		return htmlentities($value, ENT_QUOTES, config('form.encoding'), false);
	}

	/**
	 * Create a field along with a label and error message (if one is set).
	 *
	 * @param  string  $name
	 * @param  mixed   $type
	 * @param  array   $attributes
	 * @return string
	 */
	public function field($name, $type = null, $attributes = [])
	{
		//set any field named "submit" to a "submit" field automatically and set it's type to attributes to
		//to simplify creation of "submit" fields with field() macro
		if ($name == "submit") {
			if (is_array($type))
			{
				$name       = null;
				$attributes = $type;
				$type       = "submit";
			}

			$types = [
				'text',
				'search',
				'password',
				'url',
				'number',
				'date',
				'textarea',
				'hidden',
				'select',
				'checkbox',
				'radio',
				'checkbox-set',
				'radio-set',
				'file',
				'button',
				'submit',
			];

			if (!is_array($type) && !in_array($type, $types))
			{
				$name = $type;
				$type = "submit";
				$attributes = [];
			}
		}

		//allow label to be set via attributes array (defaults to labels array and then to a label derived from the field's name)
		$fieldLabel = config('form.field.auto_label');

		if (!is_null($name))
			$label = $this->nameToLabel($name);
		else
			$label = $name;

		if (is_array($attributes) && isset($attributes['label']))
		{
			$label = $attributes['label'];
			unset($attributes['label']);
			$fieldLabel = true;
		}

		if (is_null($label))
			$fieldLabel = false;

		if (!is_array($attributes))
			$attributes = [];

		//allow options for select, radio-set, and checkbox-set to be set via attributes array
		$options = [];
		if (isset($attributes['options']))
		{
			$options = $attributes['options'];
			unset($attributes['options']);
		}

		///allow the field's value to be set via attributes array
		$value = null;
		if (isset($attributes['value']))
		{
			$value = $attributes['value'];
			unset($attributes['value']);
		}

		//set any field named "password" to a "password" field automatically; no type declaration required
		if (substr($name, 0, 8) == "password" && is_null($type))
			$type = "password";

		//if type is still null, assume it to be a regular "text" field
		if (is_null($type))
			$type = "text";

		//set attributes up for label and field (remove element-specific attributes from label and vice versa)
		$attributesLabel = [];
		foreach ($attributes as $key => $attribute)
		{
			if (substr($key, -6) != "-field" && substr($key, -10) != "-container" && $key != "id") {
				$key = str_replace('-label', '', $key);
				$attributesLabel[$key] = $attribute;
			}

			if (($key == "id" || $key == "id-field") && !isset($attributes['for'])) {
				$attributesLabel['for'] = $attribute;
			}
		}

		$attributesField = ['value' => $value];

		foreach ($attributes as $key => $attribute)
		{
			if (substr($key, -6) != "-label" && substr($key, -16) != "-field-container") {
				$key = str_replace('-field', '', $key);
				$attributesField[$key] = $attribute;
			}
		}

		$html = $this->openFieldContainer($name, $type, $attributes);

		switch ($type)
		{
			case "text":
				if ($fieldLabel)
					$html .= $this->label($name, $label, $attributesLabel);

				$html .= $this->text($name, $attributesField) . "\n";
				break;

			case "search":
				if ($fieldLabel)
					$html .= $this->label($name, $label, $attributesLabel);

				$html .= $this->search($name, $attributesField) . "\n";
				break;

			case "password":
				if ($fieldLabel)
					$html .= $this->label($name, $label, $attributesLabel);

				$html .= $this->password($name, $attributesField) . "\n";
				break;
			case "url":
				if ($fieldLabel)
					$html .= $this->label($name, $label, $attributesLabel);

				$html .= $this->url($name, $attributesField) . "\n";
				break;

			case "number":
				if ($fieldLabel)
					$html .= $this->label($name, $label, $attributesLabel);

				$html .= $this->number($name, $attributesField) . "\n";
				break;

			case "date":
				if ($fieldLabel)
					$html .= $this->label($name, $label, $attributesLabel);

				$html .= $this->date($name, $attributesField) . "\n";
				break;

			case "textarea":
				if ($fieldLabel)
					$html .= $this->label($name, $label, $attributesLabel);

				$html .= $this->textarea($name, $attributesField);
				break;

			case "hidden":
				$html .= $this->hidden($name, $attributesField);
				break;

			case "select":
				if ($fieldLabel)
					$html .= $this->label($name, $label, $attributesLabel);

				$html .= $this->select($name, $options, $attributesField);
				break;

			case "checkbox":
				if (isset($attributesLabel['class']))
					$attributesLabel['class'] .= " checkbox";
				else
					$attributesLabel['class']  = "checkbox";

				$html .= '<label>'.$this->checkbox($name, $attributesField).' '.$label.'</label>';
				break;

			case "radio":
				if (isset($attributesLabel['class']))
					$attributesLabel['class'] .= " radio";
				else
					$attributesLabel['class']  = "radio";

				$html .= '<label>'.$this->radio($name, $value, $attributesField).' '.$label.'</label>';
				break;
			case "checkbox-set":
				//for checkbox set, use options as array of checkbox names
				if ($fieldLabel)
					$html .= $this->label(null, $label, $attributesLabel);

				if (!is_null($name))
					$attributesField['name-prefix'] = $name;

				$html .= $this->checkboxSet($options, $attributesField);
				break;

			case "radio-set":
				if ($fieldLabel)
					$html .= $this->label(null, $label, $attributesLabel);

				$html .= $this->radioSet($name, $options, $attributesField);
				break;

			case "file":
				if ($fieldLabel)
					$html .= $this->label($name, $label, $attributesLabel);

				$html .= $this->file($name, $attributesField) . "\n";
				break;

			case "button":
				$html .= $this->button($label, $attributesField);
				break;

			case "submit":
				$html .= $this->submit($label, $attributesField);
				break;
		}

		if (config('form.field_container.error') && !config('form.error.type_label_tooltip'))
			$html .= $this->error($name) . "\n";

		$html .= $this->closeFieldContainer();

		return $html;
	}

	/**
	 * Open a field container.
	 *
	 * @param  string  $name
	 * @param  mixed   $type
	 * @param  array   $attributes
	 * @return string
	 */
	public function openFieldContainer($name, $type = null, $attributes = [])
	{
		$attributesFieldContainer = [];
		foreach ($attributes as $key => $attribute) {
			if (substr($key, -16) == "-field-container")
			{
				$key = str_replace('-field-container', '', $key);
				$attributesFieldContainer[$key] = $attribute;
			}
		}

		if (!isset($attributesFieldContainer['class']) || $attributesFieldContainer['class'] == "")
			$attributesFieldContainer['class'] = config('form.field_container.class');
		else
			$attributesFieldContainer['class'] .= ' '.config('form.field_container.class');

		if (!isset($attributesFieldContainer['id'])) {
			$attributesFieldContainer['id'] = $this->id($name, $attributesFieldContainer).'-area';
		} else {
			if (is_null($attributesFieldContainer['id']) || !$attributesFieldContainer['id'])
				unset($attributesFieldContainer['id']);
		}

		if (in_array($type, ['checkbox', 'radio', 'hidden']))
			$attributesFieldContainer['class'] .= ' '.$type;

		$attributesFieldContainer = $this->addErrorClass($name, $attributesFieldContainer);

		return '<'.config('form.field_container.element').$this->attributes($attributesFieldContainer).'>' . "\n";
	}

	/**
	 * Close a field container.
	 *
	 * @return string
	 */
	public function closeFieldContainer()
	{
		$html = "";

		if (config('form.field_container.clear'))
			$html .= '<div class="clear"></div>' . "\n";

		$html .= '</'.config('form.field_container.element').'>' . "\n";

		return $html;
	}

	/**
	 * Create an HTML input element.
	 *
	 * <code>
	 *		// Create a "text" input element named "email"
	 *		echo Form::input('text', 'email');
	 *
	 *		// Create an input element with a specified default value
	 *		echo Form::input('text', 'email', 'example@gmail.com');
	 * </code>
	 *
	 * @param  string  $type
	 * @param  string  $name
	 * @param  array   $attributes
	 * @return string
	 */
	public function input($type, $name, $attributes = [])
	{
		if (!isset($attributes['value']))
			$attributes['value'] = null;

		//automatically set placeholder attribute if config option is set
		if (!in_array($type, ['hidden', 'checkbox', 'radio']))
			$attributes = $this->setFieldPlaceholder($name, $attributes);

		//add the field class if config option is set
		$attributes = $this->setFieldClass($name, $attributes, $type);

		//remove "placeholder" attribute if it is set to false
		if (isset($attributes['placeholder']) && !$attributes['placeholder'])
			unset($attributes['placeholder']);

		$name       = (isset($attributes['name'])) ? $attributes['name'] : $name;
		$attributes = $this->addErrorClass($name, $attributes);

		$attributes['id'] = $this->id($name, $attributes);

		if ($name == $this->spoofer || $name == "_token")
			unset($attributes['id']);

		if (is_null($attributes['value']) && $type != "password")
			$attributes['value'] = $this->value($name);

		if (isset($attributes['value']))
			$attributes['value'] = str_replace('"', '&quot;', $attributes['value']);

		$name = $this->name($name);

		if ($type != "hidden")
			$attributes = $this->addAccessKey($name, null, $attributes);

		$attributes = array_merge($attributes, compact('type', 'name'));

		return '<input'.$this->attributes($attributes).'>' . "\n";
	}

	/**
	 * Create an HTML text input element.
	 *
	 * @param  string  $name
	 * @param  array   $attributes
	 * @return string
	 */
	public function text($name, $attributes = [])
	{
		return $this->input('text', $name, $attributes);
	}

	/**
	 * Create an HTML password input element.
	 *
	 * @param  string  $name
	 * @param  array   $attributes
	 * @return string
	 */
	public function password($name, $attributes = [])
	{
		return $this->input('password', $name, $attributes);
	}

	/**
	 * Create an HTML hidden input element.
	 *
	 * @param  string  $name
	 * @param  array   $attributes
	 * @return string
	 */
	public function hidden($name, $attributes = [])
	{
		return $this->input('hidden', $name, $attributes);
	}

	/**
	 * Create an HTML search input element.
	 *
	 * @param  string  $name
	 * @param  array   $attributes
	 * @return string
	 */
	public function search($name, $attributes = [])
	{
		return $this->input('search', $name, $attributes);
	}

	/**
	 * Create an HTML email input element.
	 *
	 * @param  string  $name
	 * @param  array   $attributes
	 * @return string
	 */
	public function email($name, $attributes = [])
	{
		return $this->input('email', $name, $attributes);
	}

	/**
	 * Create an HTML telephone input element.
	 *
	 * @param  string  $name
	 * @param  array   $attributes
	 * @return string
	 */
	public function telephone($name, $attributes = [])
	{
		return $this->input('tel', $name, $attributes);
	}

	/**
	 * Create an HTML URL input element.
	 *
	 * @param  string  $name
	 * @param  array   $attributes
	 * @return string
	 */
	public function url($name, $attributes = [])
	{
		return $this->input('url', $name, $attributes);
	}

	/**
	 * Create an HTML number input element.
	 *
	 * @param  string  $name
	 * @param  array   $attributes
	 * @return string
	 */
	public function number($name, $attributes = [])
	{
		return $this->input('number', $name, $attributes);
	}

	/**
	 * Create an HTML date input element.
	 *
	 * @param  string  $name
	 * @param  array   $attributes
	 * @return string
	 */
	public function date($name, $attributes = [])
	{
		return $this->input('date', $name, $attributes);
	}

	/**
	 * Create an HTML file input element.
	 *
	 * @param  string  $name
	 * @param  array   $attributes
	 * @return string
	 */
	public function file($name, $attributes = [])
	{
		return $this->input('file', $name, $attributes);
	}

	/**
	 * Create an HTML range input element.
	 *
	 * @param  string  $name
	 * @param  array   $attributes
	 * @return string
	 */
	public function range($name, $attributes = [])
	{
		$output = true;
		if (isset($attributes['output']))
		{
			$output = $attributes['output'];
			unset($attributes['output']);
		}

		if (!isset($attributes['value']))
			$attributes['value'] = $this->value($name);

		//set value to minimum if it is not set
		if ((is_null($attributes['value']) || $attributes['value'] == "") && isset($attributes['min']))
			$attributes['value'] = $attributes['min'];

		$html = $this->input('range', $name, $attributes);

		if ($output)
			$html .= '<output for="'.$this->id($name).'" id="'.$this->id($name).'-output" class="range">'.$attributes['value'].'</output>' . "\n";

		return $html;
	}

	/**
	 * Create an HTML textarea element.
	 *
	 * @param  string  $name
	 * @param  array   $attributes
	 * @return string
	 */
	public function textarea($name, $attributes = [])
	{
		$attributes['name'] = $name;
		$attributes['id']   = $this->id($name, $attributes);

		//add the field class if config option is set
		$attributes = $this->setFieldClass($name, $attributes);

		//automatically set placeholder attribute if config option is set
		$attributes = $this->setFieldPlaceholder($name, $attributes);

		$attributes = $this->addErrorClass($name, $attributes);

		$value = isset($attributes['value']) ? $attributes['value'] : $value = $this->value($name);

		if (isset($attributes['value']))
			unset($attributes['value']);

		$attributes['name'] = $this->name($attributes['name']);

		$attributes = $this->addAccessKey($name, null, $attributes);
		$attributes = $this->setTextAreaSize($attributes);

		if (isset($attributes['size']))
			unset($attributes['size']);

		return '<textarea'.$this->attributes($attributes).'>'.$this->entities($value).'</textarea>' . "\n";
	}

	/**
	 * Set the text area size on the attributes.
	 *
	 * @param  array  $options
	 * @return array
	 */
	protected function setTextAreaSize($options)
	{
		if (isset($options['size']))
		{
			return $this->setQuickTextAreaSize($options);
		}

		// If the "size" attribute was not specified, we will just look for the regular
		// columns and rows attributes, using sane defaults if these do not exist on
		// the attributes array. We'll then return this entire options array back.
		$cols = array_get($options, 'cols', 50);

		$rows = array_get($options, 'rows', 10);

		return array_merge($options, compact('cols', 'rows'));
	}

	/**
	 * Set the text area size using the quick "size" attribute.
	 *
	 * @param  array  $options
	 * @return array
	 */
	protected function setQuickTextAreaSize($options)
	{
		$segments = explode('x', $options['size']);

		return array_merge($options, array('cols' => $segments[0], 'rows' => $segments[1]));
	}

	/**
	 * Create an HTML select element.
	 *
	 * <code>
	 *		// Create a HTML select element filled with options
	 *		echo Form::select('sizes', [('S' => 'Small', 'L' => 'Large']);
	 *
	 *		// Create a select element with a default selected value
	 *		echo Form::select('sizes', ['S' => 'Small', 'L' => 'Large'], 'Select a size', 'L');
	 * </code>
	 *
	 * @param  string  $name
	 * @param  array   $options
	 * @param  array   $attributes
	 * @return string
	 */
	public function select($name, $options = [], $attributes = [])
	{
		if (!isset($attributes['id']))
			$attributes['id'] = $this->id($name, $attributes);

		$attributes['name'] = $name;
		$attributes         = $this->addErrorClass($name, $attributes);

		//allow value to be set with "value" or "selected" attribute
		$value = null;
		if (isset($attributes['value']))
		{
			$value = $attributes['value'];
			unset($attributes['value']);
		}

		if (isset($attributes['selected']))
		{
			$value = $attributes['selected'];
			unset($attributes['selected']);
		}

		if (is_null($value))
			$value = $this->value($name);

		if ((is_null($value) || $value == "") && substr($name, -1) == ".")
			$value = $this->value(substr($name, 0, (strlen($name) - 1)));

		$value = str_replace('"', '&quot;', $value);

		//add the field class if config option is set
		$attributes = $this->setFieldClass($name, $attributes);

		$html = [];

		if (isset($attributes['multiple']))
		{
			if ($attributes['multiple'] !== false)
				$attributes['multiple'] = "multiple";
			else
				unset($attributes['multiple']);
		}

		if (isset($attributes['null-option']))
		{
			if (!is_null($attributes['null-option']) && $attributes['null-option'] !== false)
			{
				$html[] = $this->option('', $attributes['null-option'], $value);

				$attributes['data-null-option'] = $attributes['null-option'];
			}

			unset($attributes['null-option']);
		} else {
			if (!isset($attributes['multiple']))
			{
				$defaultNullOption = config('form.field.default_null_option');

				if ($defaultNullOption !== false)
				{
					if (!is_string($defaultNullOption))
						$defaultNullOption = trans('formation::labels.default_null_option');

					$html[] = $this->option('', $defaultNullOption, $value);

					$attributes['data-null-option'] = $defaultNullOption;
				}
			}
		}

		foreach ($options as $optionValue => $optionLabel)
		{
			//allow the possibility of the same value appearing in the options array twice by appending "[DUPLICATE]" to its key
			$optionValue = str_replace('[DUPLICATE]', '', $optionValue);

			if (is_array($optionLabel))
				$html[] = $this->optionGroup($optionLabel, $optionValue, $value);
			else
				$html[] = $this->option($optionValue, $optionLabel, $value);
		}

		//make multiple select name into array if it is not already
		if (isset($attributes['multiple']) && substr($attributes['name'], -1) != ".")
			$attributes['name'] .= ".";

		$attributes['name'] = $this->name($attributes['name']);

		$attributes = $this->addAccessKey($name, null, $attributes);

		return '<select'.$this->attributes($attributes).'>'.implode("\n", $html). "\n" .'</select>' . "\n";
	}

	/**
	 * Create an option group form element.
	 *
	 * @param  array   $list
	 * @param  string  $label
	 * @param  string  $selected
	 * @return string
	 */
	protected function optionGroup($list, $label, $selected)
	{
		$html = [];

		foreach ($list as $value => $display)
		{
			$html[] = $this->option($display, $value, $selected);
		}

		return '<optgroup label="'.e($label).'">'.implode('', $html).'</optgroup>';
	}

	/**
	 * Create an HTML select element option.
	 *
	 * @param  string  $value
	 * @param  string  $label
	 * @param  string  $selected
	 * @return string
	 */
	protected function option($value, $label, $selected)
	{
		if (is_array($selected))
			$selected = (in_array($value, $selected)) ? 'selected' : null;
		else
			$selected = ((string) $value == (string) $selected) ? 'selected' : null;

		$attributes = [
			'value'    => $this->entities($value),
			'selected' => $selected,
		];

		return '<option'.$this->attributes($attributes).'>'.$this->entities($label).'</option>';
	}

	/**
	 * Create a set of select boxes for times.
	 *
	 * @param  string  $name
	 * @param  array   $attributes
	 * @return string
	 */
	public function selectTime($namePrefix = 'time', $attributes = [])
	{
		$html = "";
		if ($namePrefix != "" && substr($namePrefix, -1) != "_")
			$namePrefix .= "_";

		//create hour field
		$hoursOptions = [];
		for ($h=0; $h <= 12; $h++)
		{
			$hour = sprintf('%02d', $h);
			if ($hour == 12) {
				$hoursOptions[$hour.'[DUPLICATE]'] = $hour;
			} else {
				if ($h == 0) $hour = 12;
				$hoursOptions[$hour] = $hour;
			}
		}

		$attributesHour = $attributes;

		if (isset($attributesHour['class']))
			$attributesHour['class'] .= " time time-hour";
		else
			$attributesHour['class'] = "time time-hour";

		$html .= $this->select($namePrefix.'hour', $hoursOptions, $attributesHour);

		$html .= '<span class="time-hour-minutes-separator">:</span>' . "\n";

		//create minutes field
		$minutesOptions = [];
		$attributes['minute-interval'] = !isset($attributes['minute-interval']) ? (int) $attributes['minute-interval'] : 1;

		if (!in_array($attributes['minute-inteval'], [15, 30]))
			$attributes['minute-inteval'] = 1;

		for ($m = 0; $m < 60; $m++)
		{
			$minute = sprintf('%02d', $m);
			$minutesOptions[$minute] = $minute;
		}

		unset($attributes['minute-interval']);

		$attributesMinutes = $attributes;

		if (isset($attributesMinutes['class']))
			$attributesMinutes['class'] .= " time time-minutes";
		else
			$attributesMinutes['class'] = "time time-minutes";

		$html .= $this->select($namePrefix.'minutes', $minutesOptions, $attributesMinutes);

		//create meridiem field
		$meridiemOptions    = $this->simpleOptions(['am', 'pm']);
		$attributesMeridiem = $attributes;

		if (isset($attributesMeridiem['class']))
			$attributesMeridiem['class'] .= " time time-meridiem";
		else
			$attributesMeridiem['class'] = "time time-meridiem";

		$html .= $this->select($namePrefix.'meridiem', $meridiemOptions, $attributesMeridiem);
		return $html;
	}

	/**
	 * Create a set of HTML checkboxes.
	 *
	 * @param  array   $names
	 * @param  array   $attributes
	 * @return string
	 */
	public function checkboxSet($names = [], $attributes = [])
	{
		if (!empty($names) && (is_object($names) || is_array($names)))
		{
			if (is_object($names))
				$names = (array) $names;

			$containerAttributes = ['class' => 'checkbox-set'];

			if (isset($attributes['name-prefix']))
			{
				$namePrefix = $attributes['name-prefix'];

				$containerAttributes['id'] = $this->id($namePrefix, $attributes);

				unset($attributes['name-prefix']);
			} else {
				$namePrefix = null;
			}

			foreach ($attributes as $attribute => $value)
			{
				//appending "-container" to attributes means they apply to the
				//"checkbox-set" container rather than to the checkboxes themselves
				if (substr($attribute, -10) == "-container")
				{
					if (str_replace('-container', '', $attribute) == "class")
						$containerAttributes['class'] .= ' '.$value;
					else
						$containerAttributes[str_replace('-container', '', $attribute)] = $value;

					unset($attributes[$attribute]);
				}
			}

			$containerAttributes = $this->addErrorClass('roles', $containerAttributes);
			$html = '<div'.$this->attributes($containerAttributes).'>';

			foreach ($names as $name => $label)
			{
				//if a simple array is used, automatically create the label from the name
				$associativeArray = true;
				if (isset($attributes['associative']))
				{
					if (!$attributes['associative'])
						$associativeArray = false;
				} else {
					if (is_numeric($name))
						$associativeArray = false;
				}

				if (!$associativeArray) {
					$name  = $label;
					$label = $this->nameToLabel($name);
				}

				if (isset($attributes['name-values']) && $attributes['name-values'])
					$value = $name;
				elseif (isset($attributes['label-values']) && $attributes['label-values'])
					$value = $label;
				else
					$value = 1;

				$nameToCheck = $name;
				if (!is_null($namePrefix))
				{
					if (substr($namePrefix, -1) == ".") {
						$nameToCheck = $namePrefix . $name;
						$name        = $nameToCheck;
					} else {
						$nameToCheck = $namePrefix;
						$name        = $namePrefix . '.('.$name.')';
					}
				}

				$valueToCheck = $this->value($nameToCheck);
				$checked      = false;

				if (is_array($valueToCheck) && in_array($value, $valueToCheck)) {
					$checked = true;
				} else if (is_bool($value) && $value == $this->value($nameToCheck, 'checkbox')) {
					$checked = true;
				} else if ($value && $value == $valueToCheck) {
					$checked = true;
				} else if (!$value && $value === $valueToCheck) {
					$checked = true;
				}

				//add selected class to list item if checkbox is checked to allow styling for selected checkboxes in set
				$subContainerAttributes = ['class' => 'checkbox'];
				if ($checked)
					$subContainerAttributes['class'] .= ' selected';

				$checkbox = '<div'.$this->attributes($subContainerAttributes).'>' . "\n";

				$checkboxAttributes            = $attributes;
				$checkboxAttributes['id']      = $this->id($name);
				$checkboxAttributes['value']   = $value;
				$checkboxAttributes['checked'] = $checked;

				if (isset($checkboxAttributes['associative']))
					unset($checkboxAttributes['associative']);

				if (isset($checkboxAttributes['name-values']))
					unset($checkboxAttributes['name-values']);

				$checkbox .= $this->checkbox($name, $checkboxAttributes);
				$checkbox .= $this->label($name, $label, ['accesskey' => false]);
				$checkbox .= '</div>' . "\n";
				$html     .= $checkbox;
			}

			$html .= '</div>' . "\n";
			return $html;
		}
	}

	/**
	 * Create an HTML checkbox input element.
	 *
	 * <code>
	 *		// Create a checkbox element
	 *		echo Form::checkbox('terms', 'yes');
	 * </code>
	 *
	 * @param  string  $name
	 * @param  array   $attributes
	 * @return string
	 */
	public function checkbox($name, $attributes = [])
	{
		if (!isset($attributes['value']))
			$attributes['value'] = 1;

		if (is_string($attributes['value']))
			$attributes['value'] = str_replace('"', '&quot;', $attributes['value']);

		if ($attributes['value'] == $this->value($name) && !isset($attributes['checked']))
			$attributes['checked'] = true;

		if (isset($attributes['checked']) && $attributes['checked'] == false)
			unset($attributes['checked']);

		return $this->checkable('checkbox', $name, $attributes);
	}

	/**
	 * Create a set of HTML radio buttons.
	 *
	 * @param  string  $name
	 * @param  array   $options
	 * @param  array   $attributes
	 * @return string
	 */
	public function radioSet($name, $options = [], $attributes = [])
	{
		if (!empty($options) && (is_object($options) || is_array($options)))
		{
			if (is_object($options))
				$options = (array) $options;

			$containerAttributes = ['class' => 'radio-set', 'id' => $this->id($name, $attributes)];

			foreach ($attributes as $attribute => $value)
			{
				//appending "-container" to attributes means they apply to the
				//"radio-set" container rather than to the checkboxes themselves
				if (substr($attribute, -10) == "-container")
				{
					if (str_replace('-container', '', $attribute) == "class")
						$containerAttributes['class'] .= ' '.$value;
					else
						$containerAttributes[str_replace('-container', '', $attribute)] = $value;

					unset($attributes[$attribute]);
				}
			}

			$containerAttributes = $this->addErrorClass($name, $containerAttributes);
			$html                = '<div'.$this->attributes($containerAttributes).'>';

			$label    = $this->label($name); //set dummy label so ID can be created in line below
			$idPrefix = $this->id($name, $attributes);

			if (!isset($attributes['value']) || is_null($attributes['value']))
				$attributes['value'] = $this->value($name);

			foreach ($options as $optionValue => $optionLabel)
			{
				$radioButtonAttributes = $attributes;

				//add selected class to list item if radio button is set to allow styling for selected radio buttons in set
				$subContainerAttributes = ['class' => 'radio'];

				if ($attributes['value'] == (string) $optionValue)
					$radioButtonAttributes['checked'] = "checked";

				if (isset($radioButtonAttributes['checked']))
				{
					$radioButtonAttributes['checked'] = "checked";
					$subContainerAttributes['class'] .= ' selected';
				}

				$radioButton = '<div'.$this->attributes($subContainerAttributes).'>' . "\n";

				//append radio button value to the end of ID to prevent all radio buttons from having the same ID
				$idSuffix = str_replace('"', '', str_replace('.', '-', str_replace(' ', '-', str_replace('_', '-', strtolower($optionValue)))));
				if ($idSuffix == "")
					$idSuffix = "blank";

				$radioButtonAttributes['id'] = $idPrefix.'-'.$idSuffix;

				$radioButton .= '<label>'.$this->radio($name, $optionValue, $radioButtonAttributes).' '.$optionLabel.'</label></div>' . "\n";
				$html        .= $radioButton;
			}

			$html .= '</div>' . "\n";
			return $html;
		}
	}

	/**
	 * Create an HTML radio button input element.
	 *
	 * <code>
	 *		// Create a radio button element
	 *		echo Form::radio('drinks', 'Milk');
	 * </code>
	 *
	 * @param  string  $name
	 * @param  mixed   $value
	 * @param  array   $attributes
	 * @return string
	 */
	public function radio($name, $value = null, $attributes = [])
	{
		if (is_null($value))
			$value = $name;

		$value = str_replace('"', '&quot;', $value);

		$attributes['value'] = $value;

		if ((string) $value === $this->value($name) && !isset($attributes['checked']))
			$attributes['checked'] = true;

		if (!isset($attributes['id']))
			$attributes['id'] = $this->id($name.'-'.strtolower($value), $attributes);

		return $this->checkable('radio', $name, $attributes);
	}

	/**
	 * Create a checkable (checkbox or radio button) input element.
	 *
	 * @param  string  $type
	 * @param  string  $name
	 * @param  string  $value
	 * @param  bool    $checked
	 * @param  array   $attributes
	 * @return string
	 */
	protected function checkable($type, $name, $attributes)
	{
		return $this->input($type, $name, $attributes);
	}

	/**
	 * Get the check state for a checkable input.
	 *
	 * @param  string  $type
	 * @param  string  $name
	 * @param  mixed   $value
	 * @param  bool    $checked
	 * @return bool
	 */
	protected function getCheckedState($type, $name, $value, $checked)
	{
		switch ($type)
		{
			case 'checkbox':
				return $this->getCheckboxCheckedState($name, $value, $checked);

			case 'radio':
				return $this->getRadioCheckedState($name, $value, $checked);

			default:
				return $this->getValueAttribute($name) == $value;
		}
	}

	/**
	 * Get the check state for a checkbox input.
	 *
	 * @param  string  $name
	 * @param  mixed  $value
	 * @param  bool  $checked
	 * @return bool
	 */
	protected function getCheckboxCheckedState($name, $value, $checked)
	{
		if (isset($this->session) && ! $this->oldInputIsEmpty() && is_null($this->old($name))) return false;

		if ($this->missingOldAndModel($name)) return $checked;

		$posted = $this->getValueAttribute($name);

		return is_array($posted) ? in_array($value, $posted) : (bool) $posted;
	}

	/**
	 * Get the check state for a radio input.
	 *
	 * @param  string  $name
	 * @param  mixed  $value
	 * @param  bool  $checked
	 * @return bool
	 */
	protected function getRadioCheckedState($name, $value, $checked)
	{
		if ($this->missingOldAndModel($name)) return $checked;

		return $this->getValueAttribute($name) == $value;
	}

	/**
	 * Determine if old input or model input exists for a key.
	 *
	 * @param  string  $name
	 * @return bool
	 */
	protected function missingOldAndModel($name)
	{
		return (is_null($this->old($name)) && is_null($this->getModelValueAttribute($name)));
	}

	/**
	 * Prepare an options array from a database object or other complex
	 * object/array for a select field, checkbox set, or radio button set.
	 *
	 * @param  array   $options
	 * @param  array   $vars
	 * @return array
	 */
	public function prepOptions($options = [], $vars = [])
	{
		$optionsFormatted = [];

		//turn Eloquent instances into an array
		$optionsArray = $options;
		if (isset($optionsArray[0]) && isset($optionsArray[0]->incrementing) && isset($optionsArray[0]->timestamps))
			$optionsArray = $options->toArray();

		if (is_string($vars) || (is_array($vars) && count($vars) > 0))
		{
			foreach ($optionsArray as $key => $option)
			{
				//turn object into array
				$optionArray = $option;
				if (is_object($option))
					$optionArray = (array) $option;

				//set label and value according to specified variables
				if (is_string($vars)) {
					$label = $vars;
					$value = $vars;
				} else if (is_array($vars) && count($vars) == 1) {
					$label = $vars[0];
					$value = $vars[0];
				} else {
					$label = $vars[0];
					$value = $vars[1];
				}

				if (isset($optionValue))
					unset($optionValue);

				$method = Format::getMethodFromString($value);

				if (!is_null($method)) //value is a method of object; call it
				{
					$optionValue = call_user_func_array([$options[$key], $method['name']], $method['parameters']);
				} else if (isset($optionArray[$value])) {
					$optionValue = $optionArray[$value];
				}

				//if a label and a value are set, add it to options array
				if (isset($optionArray[$label]) && isset($optionValue))
					$optionsFormatted[$optionArray[$label]] = $optionValue;
			}
		}

		return $optionsFormatted;
	}

	/**
	 * Create an associative array from a simple array for a select field, checkbox set, or radio button set.
	 *
	 * @param  array   $options
	 * @return array
	 */
	public function simpleOptions($options = [])
	{
		$optionsFormatted = [];

		foreach ($options as $option) {
			$optionsFormatted[$option] = $option;
		}

		return $optionsFormatted;
	}

	/**
	 * Create an associative array from a simple array for a checkbox set. The field name will be lowercased and underscored.
	 *
	 * @param  array   $options
	 * @return array
	 */
	public function checkboxOptions($options = [])
	{
		$optionsFormatted = [];

		foreach ($options as $option) {
			$fieldName = strtolower(str_replace('.', '', str_replace(' ', '_', trim($option))));

			$optionsFormatted[$fieldName] = $option;
		}

		return $optionsFormatted;
	}

	/**
	 * Offset a simple array by 1 index to prevent any options from having an
	 * index (value) of 0 for a select field, checkbox set, or radio button set.
	 *
	 * @param  array   $options
	 * @return array
	 */
	public function offsetOptions($options = [])
	{
		$optionsFormatted = [];

		for ($o=0; $o < count($options); $o++) {
			$optionsFormatted[($o + 1)] = $options[$o];
		}

		return $optionsFormatted;
	}

	/**
	 * Create an options array of numbers within a specified range
	 * for a select field, checkbox set, or radio button set.
	 *
	 * @param  integer $start
	 * @param  integer $end
	 * @param  integer $increment
	 * @param  integer $decimals
	 * @return array
	 */
	public function numberOptions($start = 1, $end = 10, $increment = 1, $decimals = 0)
	{
		$options = [];
		if (is_numeric($start) && is_numeric($end))
		{
			if ($start <= $end)
			{
				for ($o = $start; $o <= $end; $o += $increment)
				{
					if ($decimals)
						$value = number_format($o, $decimals, '.', '');
					else
						$value = $o;

					$options[$value] = $value;
				}
			} else {
				for ($o = $start; $o >= $end; $o -= $increment)
				{
					if ($decimals)
						$value = number_format($o, $decimals, '.', '');
					else
						$value = $o;

					$options[$value] = $value;
				}
			}
		}

		return $options;
	}

	/**
	 * Get an options array of countries.
	 *
	 * @return array
	 */
	public function countryOptions()
	{
		return $this->simpleOptions([
			'Canada', 'United States', 'Afghanistan', 'Albania', 'Algeria', 'American Samoa', 'Andorra', 'Angola', 'Anguilla', 'Antarctica', 'Antigua And Barbuda', 'Argentina', 'Armenia', 'Aruba',
			'Australia', 'Austria', 'Azerbaijan', 'Bahamas', 'Bahrain', 'Bangladesh', 'Barbados', 'Belarus', 'Belgium', 'Belize', 'Benin', 'Bermuda', 'Bhutan', 'Bolivia', 'Bosnia And Herzegowina',
		 	'Botswana', 'Bouvet Island', 'Brazil', 'British Indian Ocean Territory', 'Brunei Darussalam', 'Bulgaria', 'Burkina Faso', 'Burundi', 'Cambodia', 'Cameroon', 'Cape Verde', 'Cayman Islands',
		 	'Central African Republic', 'Chad', 'Chile', 'China', 'Christmas Island', 'Cocos (Keeling) Islands', 'Colombia', 'Comoros', 'Congo', 'Congo, The Democratic Republic Of The', 'Cook Islands',
		 	'Costa Rica', 'Cote D\'Ivoire', 'Croatia (Local Name: Hrvatska)', 'Cuba', 'Cyprus', 'Czech Republic', 'Denmark', 'Djibouti', 'Dominica', 'Dominican Republic', 'East Timor', 'Ecuador','Egypt',
			'El Salvador', 'Equatorial Guinea', 'Eritrea', 'Estonia', 'Ethiopia', 'Falkland Islands (Malvinas)', 'Faroe Islands', 'Fiji', 'Finland', 'France', 'France, Metropolitan', 'French Guiana',
		 	'French Polynesia', 'French Southern Territories', 'Gabon', 'Gambia', 'Georgia', 'Germany', 'Ghana', 'Gibraltar', 'Greece', 'Greenland', 'Grenada', 'Guadeloupe', 'Guam', 'Guatemala','Guinea',
		 	'Guinea-Bissau', 'Guyana', 'Haiti', 'Heard And Mc Donald Islands', 'Holy See (Vatican City State)', 'Honduras', 'Hong Kong', 'Hungary', 'Iceland', 'India', 'Indonesia', 'Iran', 'Iraq', 'Ireland',
		 	'Israel', 'Italy', 'Jamaica', 'Japan', 'Jordan', 'Kazakhstan', 'Kenya', 'Kiribati', 'Korea, Democratic People\'S Republic Of', 'Korea, Republic Of', 'Kuwait', 'Kyrgyzstan',
		 	'Lao People\'S Democratic Republic', 'Latvia', 'Lebanon', 'Lesotho', 'Liberia', 'Libyan Arab Jamahiriya', 'Liechtenstein', 'Lithuania', 'Luxembourg', 'Macau',
		 	'Macedonia, Former Yugoslav Republic Of', 'Madagascar', 'Malawi', 'Malaysia', 'Maldives', 'Mali', 'Malta', 'Marshall Islands', 'Martinique', 'Mauritania', 'Mauritius', 'Mayotte', 'Mexico',
		 	'Micronesia, Federated States Of', 'Moldova, Republic Of', 'Monaco', 'Mongolia', 'Montserrat', 'Morocco', 'Mozambique', 'Myanmar', 'Namibia', 'Nauru', 'Nepal', 'Netherlands',
		 	'Netherlands Antilles', 'New Caledonia', 'New Zealand', 'Nicaragua', 'Niger', 'Nigeria', 'Niue', 'Norfolk Island', 'Northern Mariana Islands', 'Norway', 'Oman', 'Pakistan', 'Palau', 'Panama',
		 	'Papua New Guinea', 'Paraguay', 'Peru','Philippines', 'Pitcairn', 'Poland', 'Portugal', 'Puerto Rico', 'Qatar', 'Reunion', 'Romania', 'Russian Federation', 'Rwanda', 'Saint Kitts And Nevis',
		 	'Saint Lucia','Saint Vincent And The Grenadines', 'Samoa', 'San Marino', 'Sao Tome And Principe', 'Saudi Arabia', 'Senegal', 'Seychelles', 'Sierra Leone', 'Singapore', 'Slovakia (Slovak Republic)',
		 	'Slovenia', 'Solomon Islands', 'Somalia', 'South Africa', 'South Georgia, South Sandwich Islands', 'Spain', 'Sri Lanka', 'St. Helena', 'St. Pierre And Miquelon', 'Sudan', 'Suriname',
		 	'Svalbard And Jan Mayen Islands', 'Swaziland', 'Sweden', 'Switzerland', 'Syrian Arab Republic', 'Taiwan', 'Tajikistan', 'Tanzania, United Republic Of', 'Thailand', 'Togo', 'Tokelau', 'Tonga',
		 	'Trinidad And Tobago', 'Tunisia', 'Turkey', 'Turkmenistan', 'Turks And Caicos Islands', 'Tuvalu', 'Uganda', 'Ukraine', 'United Arab Emirates', 'United Kingdom',
		 	'United States Minor Outlying Islands', 'Uruguay', 'Uzbekistan', 'Vanuatu', 'Venezuela', 'Viet Nam', 'Virgin Islands (British)', 'Virgin Islands (U.S.)', 'Wallis And Futuna Islands',
		 	'Western Sahara', 'Yemen', 'Yugoslavia', 'Zambia', 'Zimbabwe',
		]);
	}

	/**
	 * Get an options array of Canadian provinces.
	 *
	 * @param  bool    $useAbbrev
	 * @return array
	 */
	public function provinceOptions($useAbbrev = true)
	{
		$provinces = [
			'AB' => 'Alberta',
			'BC' => 'British Columbia',
			'MB' => 'Manitoba',
			'NB' => 'New Brunswick',
			'NL' => 'Newfoundland',
			'NT' => 'Northwest Territories',
			'NS' => 'Nova Scotia',
			'NU' => 'Nunavut',
			'ON' => 'Ontario',
			'PE' => 'Prince Edward Island',
			'QC' => 'Quebec',
			'SK' => 'Saskatchewan',
			'YT' => 'Yukon Territory',
		];

		if ($useAbbrev)
			return $provinces;
		else
			return $this->simpleOptions(array_values($provinces)); //remove abbreviation keys
	}

	/**
	 * Get an options array of US states.
	 *
	 * @param  bool    $useAbbrev
	 * @return array
	 */
	public function stateOptions($useAbbrev = true)
	{
		$states = [
			'AL' => 'Alabama',
			'AK' => 'Alaska',
			'AZ' => 'Arizona',
			'AR' => 'Arkansas',
			'CA' => 'California',
			'CO' => 'Colorado',
			'CT' => 'Connecticut',
			'DE' => 'Delaware',
			'DC' => 'District of Columbia',
			'FL' => 'Florida',
			'GA' => 'Georgia',
			'HI' => 'Hawaii',
			'ID' => 'Idaho',
			'IL' => 'Illinois',
			'IN' => 'Indiana',
			'IA' => 'Iowa',
			'KS' => 'Kansas',
			'KY' => 'Kentucky',
			'LA' => 'Louisiana',
			'ME' => 'Maine',
			'MD' => 'Maryland',
			'MA' => 'Massachusetts',
			'MI' => 'Michigan',
			'MN' => 'Minnesota',
			'MS' => 'Mississippi',
			'MO' => 'Missouri',
			'MT' => 'Montana',
			'NE' => 'Nebraska',
			'NV' => 'Nevada',
			'NH' => 'New Hampshire',
			'NJ' => 'New Jersey',
			'NM' => 'New Mexico',
			'NY' => 'New York',
			'NC' => 'North Carolina',
			'ND' => 'North Dakota',
			'OH' => 'Ohio',
			'OK' => 'Oklahoma',
			'OR' => 'Oregon',
			'PA' => 'Pennsylvania',
			'PR' => 'Puerto Rico',
			'RI' => 'Rhode Island',
			'SC' => 'South Carolina',
			'SD' => 'South Dakota',
			'TN' => 'Tennessee',
			'TX' => 'Texas',
			'UT' => 'Utah',
			'VT' => 'Vermont',
			'VA' => 'Virginia',
			'VI' => 'Virgin Islands',
			'WA' => 'Washington',
			'WV' => 'West Virginia',
			'WI' => 'Wisconsin',
			'WY' => 'Wyoming',
		];

		if ($useAbbrev)
			return $states;
		else
			return $this->simpleOptions(array_values($states)); //remove abbreviation keys
	}

	/**
	 * Get an options array of times.
	 *
	 * @param  string  $minutes
	 * @param  bool    $useAbbrev
	 * @return array
	 */
	public function timeOptions($minutes = 'half')
	{
		$times          = [];
		$minutesOptions = ['00'];

		switch ($minutes)
		{
			case "full":
				$minutesOptions = ['00']; break;
			case "half":
				$minutesOptions = ['00', '30']; break;
			case "quarter":
				$minutesOptions = ['00', '15', '30', '45']; break;
			case "all":
				$minutesOptions = [];
				for ($m=0; $m < 60; $m++) {
					$minutesOptions[] = sprintf('%02d', $m);
				}
				break;
		}

		for ($h=0; $h < 24; $h++)
		{
			$hour = sprintf('%02d', $h);
			if ($h < 12)
				$meridiem = "am";
			else
				$meridiem = "pm";

			if ($h == 0)
				$hour = 12;

			if ($h > 12)
				$hour = sprintf('%02d', ($hour - 12));

			foreach ($minutesOptions as $minutes) {
				$times[sprintf('%02d', $h).':'.$minutes.':00'] = $hour.':'.$minutes.$meridiem;
			}
		}

		return $times;
	}

	/**
	 * Create an options array of months. You may use an integer to go a number of months back from your start month
	 * or you may use a date to go back or forward to a specific date. If the end month is later than the start month,
	 * the select options will go from earliest to latest. If the end month is earlier than the start month, the select
	 * options will go from latest to earliest. If an integer is used as the end month, use a negative number to go back
	 * from the start month. Setting $endDate to true will use the last day of the month instead of the first day.
	 *
	 * @param  mixed   $start
	 * @param  mixed   $end
	 * @param  boolean $endDate
	 * @param  string  $format
	 * @return array
	 */
	public function monthOptions($start = 'current', $end = -12, $endDate = false, $format = 'F Y')
	{
		//prepare start & end months
		if ($start == "current" || is_null($start) || !is_string($start))
			$start = date('Y-m-01');

		if (is_int($end))
		{
			$startMid  = date('Y-m-15', strtotime($start)); //get mid-day of month to prevent long months or short months from producing incorrect month values
			$ascending = $end > 0;

			if ($ascending)
				$end = date('Y-m-01', strtotime($startMid.' +'.$end.' months'));
			else
				$end = date('Y-m-01', strtotime($startMid.' -'.abs($end).' months'));
		} else {
			if ($end == "current")
				$end = date('Y-m-01');

			$ascending = strtotime($end) > strtotime($start);
		}

		//create list of months
		$options = [];
		$month   = $start;

		if ($ascending)
		{
			while (strtotime($month) <= strtotime($end))
			{
				$monthMid = date('Y-m-15', strtotime($month));

				if ($endDate)
					$date = $this->lastDayOfMonth($month);
				else
					$date = $month;

				$options[$date] = date($format, strtotime($date));
				$month = date('Y-m-01', strtotime($monthMid.' +1 month'));
			}
		} else {
			while (strtotime($month) >= strtotime($end))
			{
				$monthMid = date('Y-m-15', strtotime($month));

				if ($endDate)
					$date = $this->lastDayOfMonth($month);
				else
					$date = $month;

				$options[$date] = date($format, strtotime($date));
				$month = date('Y-m-01', strtotime($monthMid.' -1 month'));
			}
		}
		return $options;
	}

	/**
	 * Get the last day of the month. You can use the second argument to format the date (example: "F j, Y").
	 *
	 * @param  string  $date
	 * @param  mixed   $format
	 * @return string
	 */
	private function lastDayOfMonth($date = 'current', $format = false)
	{
		if ($date == "current") {
			$date = date('Y-m-d');
		} else {
			$date = date('Y-m-d', strtotime($date));
			$originalMonth = substr($date, 5, 2);
		}

		$year   = substr($date, 0, 4);
		$month  = substr($date, 5, 2);
		$day    = substr($date, 8, 2);
		$result = "";

		//prevent invalid dates having wrong month assigned (June 31 = July, etc...)
		if (isset($originalMonth) && $month != $originalMonth)
			$month = $originalMonth;

		if (in_array($month, ['01', '03', '05', '07', '08', '10', '12'])) {
			$lastDay = 31;
		} else if (in_array($month, ['04', '06', '09', '11'])) {
			$lastDay = 30;
		} else if ($month == "02") {
			if (($year/4) == round($year/4)) {
				if (($year/100) == round($year/100))
				{
					if (($year/400) == round($year/400))
						$lastDay = 29;
					else
						$lastDay = 28;
				} else {
					$lastDay = 29;
				}
			} else {
				$lastDay = 28;
			}
		}

		$result = $year.'-'.$month.'-'.$lastDay;

		if ($format)
			$result = $this->date($result, $format);

		return $result;
	}

	/**
	 * Create a set of boolean options (Yes/No, On/Off, Up/Down...)
	 * You may pass a string like "Yes/No" or an array with just two options.
	 *
	 * @param  mixed   $options
	 * @param  boolean $startWithOne
	 * @return array
	 */
	public function booleanOptions($options = ['Yes', 'No'], $startWithOne = true)
	{
		if (is_string($options)) $options = explode('/', $options); //allow options to be set as a string like "Yes/No"
		if (!isset($options[1])) $options[1] = "";

		if ($startWithOne) {
			return [
				1 => $options[0],
				0 => $options[1],
			];
		} else {
			return [
				0 => $options[0],
				1 => $options[1],
			];
		}
	}

	/**
	 * Get all error messages.
	 *
	 * @return array
	 */
	public function getErrors()
	{
		if (empty($this->errors)) {
			foreach ($this->validationFields as $fieldName) {
				$error = $this->errorMessage($fieldName);
				if ($error)
					$this->errors[$fieldName] = $error;
			}
		}

		return $this->errors;
	}

	/**
	 * Set error messages from session data.
	 *
	 * @param  string  $errors
	 * @return array
	 */
	public function setErrors($session = 'errors')
	{
		$this->errors = Session::get($session);

		return $this->errors;
	}

	/**
	 * Reset error messages.
	 *
	 * @param  string  $errors
	 * @return array
	 */
	public function resetErrors($session = 'errors')
	{
		if ($session)
			Session::forget($session);

		$this->errors = [];
	}

	/**
	 * Add an error class to an HTML attributes array if a validation error exists for the specified form field.
	 *
	 * @param  string  $name
	 * @param  array   $attributes
	 * @return array
	 */
	public function addErrorClass($name, $attributes = [])
	{
		if ($this->errorMessage($name)) { //an error exists; add the error class
			if (!isset($attributes['class']))
				$attributes['class'] = $this->getErrorClass();
			else
				$attributes['class'] .= " ".$this->getErrorClass();
		}
		return $attributes;
	}

	/**
	 * Add an error class to an HTML attributes array if a validation error exists for the specified form field.
	 *
	 * @param  string  $name
	 * @param  array   $attributes
	 * @return array
	 */
	public function getErrorClass()
	{
		return config('form.error.class');
	}

	/**
	 * Create error div for validation error if it exists for specified form field.
	 *
	 * @param  string  $name
	 * @param  boolean $alwaysExists
	 * @param  mixed   $replacementFieldName
	 * @param  mixed   $customMessage
	 * @return string
	 */
	public function error($name, $alwaysExists = false, $replacementFieldName = false, $customMessage = null)
	{
		if (substr($name, -1) == ".") $name = substr($name, 0, (strlen($name) - 1));

		if ($alwaysExists)
			$attr = ' id="'.$this->id($name).'-error"';
		else
			$attr = "";

		$message = $this->errorMessage($name, $replacementFieldName);

		if (!is_null($customMessage))
			$message = $customMessage;

		$errorElement = config('form.error.element');

		if ($message && $message != "") {
			return '<'.$errorElement.' class="error"'.$attr.'>'.$message.'</'.$errorElement.'>';
		} else {
			if ($alwaysExists)
				return '<'.$errorElement.' class="error"'.$attr.' style="display: none;"></'.$errorElement.'>';
		}
	}

	/**
	 * Get validation error message if it exists for specified form field. Modified to work with array fields.
	 *
	 * @param  string  $name
	 * @param  mixed   $replacementFieldName
	 * @param  boolean $ignoreIcon
	 * @return string
	 */
	public function errorMessage($name, $replacementFieldName = false, $ignoreIcon = false)
	{
		$errorMessage = false;

		//replace field name in error message with label if it exists
		$name = str_replace('(', '', str_replace(')', '', $name));
		$nameFormatted = $name;

		$specialReplacementNames = ['LOWERCASE', 'UPPERCASE', 'UPPERCASE-WORDS'];

		if ($replacementFieldName && is_string($replacementFieldName) && $replacementFieldName != ""
		&& !in_array($replacementFieldName, $specialReplacementNames)) {
			$nameFormatted = $replacementFieldName;
		} else {
			if (isset($this->labels[$name]) && $this->labels[$name] != "")
				$nameFormatted = $this->labels[$name];
			else
				$nameFormatted = $this->nameToLabel($nameFormatted);

			if (substr($nameFormatted, -1) == ":")
				$nameFormatted = substr($nameFormatted, 0, (strlen($nameFormatted) - 1));

			$nameFormatted = $this->formatReplacementName($nameFormatted, $replacementFieldName);
		}

		if ($nameFormatted == strip_tags($nameFormatted))
			$nameFormatted = $this->entities($nameFormatted);

		//return error message if it already exists
		if (isset($this->errors[$name]))
			$errorMessage = str_replace($this->nameToLabel($name), $nameFormatted, $this->errors[$name]);

		//cycle through all validation instances to allow the ability to get error messages in root fields
		//as well as field arrays like "field[array]" (passed to errorMessage in the form of "field.array")
		foreach ($this->validation as $fieldName => $validation)
		{
			$valid = $validation->passes();

			if ($validation->messages())
			{
				$messages  = $validation->messages();
				$nameArray = explode('.', $name);

				if (count($nameArray) < 2)
				{
					if ($_POST && $fieldName == "root" && $messages->first($name) != "") {
						$this->errors[$name] = str_replace(str_replace('_', ' ', $name), $nameFormatted, $messages->first($name));
						$errorMessage = $this->errors[$name];
					}
				} else {
					$last  = $nameArray[(count($nameArray) - 1)];
					$first = str_replace('.'.$nameArray[(count($nameArray) - 1)], '', $name);

					if ($replacementFieldName && is_string($replacementFieldName) && $replacementFieldName != ""
					&& !in_array($replacementFieldName, $specialReplacementNames)) {
						$nameFormatted = $replacementFieldName;
					} else {
						if ($nameFormatted == $name)
							$nameFormatted = $this->entities(ucwords($last));

						if (substr($nameFormatted, -1) == ":")
							$nameFormatted = substr($nameFormatted, 0, (strlen($nameFormatted) - 2));

						$nameFormatted = $this->formatReplacementName($nameFormatted, $replacementFieldName);
					}

					if ($_POST && $fieldName == $first && $messages->first($last) != "") {
						$this->errors[$name] = str_replace(str_replace('_', ' ', $last), $nameFormatted, $messages->first($last));
						$errorMessage = $this->errors[$name];
					}
				}
			}
		}

		if ($errorMessage && !$ignoreIcon)
		{
			$errorIcon = config('form.error.icon');

			if ($errorIcon) {
				if (!preg_match("/glyphicon/", $errorMessage))
					$errorMessage = '<span class="glyphicon glyphicon-'.$errorIcon.'"></span>&nbsp; '.$errorMessage;
			}
		}

		return $errorMessage;
	}

	/**
	 * Format replacement name for error messages.
	 *
	 * @param  string  $name
	 * @param  mixed   $replacementName
	 * @return string
	 */
	private function formatReplacementName($name, $replacementName)
	{
		if ($replacementName == "LOWERCASE")
			$name = strtolower($name);

		if ($replacementName == "UPPERCASE")
			$name = strtoupper($name);

		if ($replacementName == "UPPERCASE-WORDS")
			$name = ucwords(strtolower($name));

		return $name;
	}

	/**
	 * Get JSON encoded errors for formation.js.
	 *
	 * @param  string  $errors
	 * @return string
	 */
	public function getJsonErrors($session = 'errors')
	{
		$errors = $this->setErrors($session);

		if (empty($errors))
			$errors = [];

		return str_replace('\\"', '\\\"', json_encode($errors));
	}

	/**
	 * Get JSON encoded errors for formation.js.
	 *
	 * @param  string  $errors
	 * @return string
	 */
	public function getJsonErrorSettings($session = 'errors')
	{
		$errorSettings = $this->formatSettingsForJs(config('form.error'));

		return json_encode($errorSettings);
	}

	/**
	 * Format settings array for Javascript.
	 *
	 * @param  array   $settings
	 * @return array
	 */
	private function formatSettingsForJs($settings)
	{
		if (is_array($settings)) {
			foreach ($settings as $setting => $value) {
				$settingOriginal = $setting;

				if ($setting == "class")
					$setting = "classAttribute";

				$setting = $this->dashedToCamelCase($setting);

				if ($setting != $settingOriginal && isset($settings[$settingOriginal]))
					unset($settings[$settingOriginal]);

				$settings[$setting] = $this->formatSettingsForJs($value);
			}
		}
		return $settings;
	}

	/**
	 * Turn a dash formatted string into a camel case formatted string.
	 *
	 * @param  string  $string
	 * @return string
	 */
	public function dashedToCamelCase($string)
	{
		$string    = str_replace(' ', '', ucwords(str_replace('-', ' ', $string)));
		$string[0] = strtolower($string[0]);

		return $string;
	}

	/**
	 * Turn an underscore formatted string into a camel case formatted string.
	 *
	 * @param  string  $string
	 * @return string
	 */
	public function underscoredToCamelCase($string)
	{
		$string    = str_replace(' ', '', ucwords(str_replace('_', ' ', $string)));
		$string[0] = strtolower($string[0]);

		return $string;
	}

	/**
	 * Turn a camel case formatted string into an underscore formatted string.
	 *
	 * @param  string  $string
	 * @return string
	 */
	public function camelCaseToUnderscore($string)
	{
		return strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $string));
	}

	/**
	 * Get the validators array.
	 *
	 */
	public function getValidation()
	{
		return $this->validation;
	}

	/**
	 * Create an HTML submit input element.
	 *
	 * @param  string  $value
	 * @param  array   $attributes
	 * @return string
	 */
	public function submit($value = 'Submit', $attributes = [])
	{
		$attributes['value'] = $value;

		return $this->input('submit', null, $attributes);
	}

	/**
	 * Create an HTML reset input element.
	 *
	 * @param  string  $value
	 * @param  array   $attributes
	 * @return string
	 */
	public function reset($value = null, $attributes = [])
	{
		$attributes['value'] = $value;

		return $this->input('reset', null, $attributes);
	}

	/**
	 * Create an HTML image input element.
	 *
	 * <code>
	 *		// Create an image input element
	 *		echo Form::image('img/submit.png');
	 * </code>
	 *
	 * @param  string  $url
	 * @param  string  $name
	 * @param  array   $attributes
	 * @return string
	 */
	public function image($url, $name = null, $attributes = [])
	{
		$attributes['src'] = URL::toAsset($url);

		return $this->input('image', $name, $attributes);
	}

	/**
	 * Create an HTML button element.
	 *
	 * @param  string  $value
	 * @param  array   $attributes
	 * @return string
	 */
	public function button($value = null, $attributes = [])
	{
		if (!isset($attributes['class']))
			$attributes['class'] = 'btn btn-default';
		else
			$attributes['class'] .= ' btn btn-default';

		if ($value == strip_tags($value))
			$value = $this->entities($value);

		return '<button'.$this->attributes($attributes).'>'.$value.'</button>' . "\n";
	}

	/**
	 * Create a label for a submit function based on a resource controller URL.
	 *
	 * @param  mixed   $itemName
	 * @param  mixed   $update
	 * @param  mixed   $icon
	 * @return string
	 */
	public function submitResource($itemName = null, $update = null, $icon = null)
	{
		//if null, check config button icon config setting
		if (is_null($icon))
			$icon = config('form.auto_button_icon');

		if (is_null($update))
			$update = $this->updateResource();

		if ($update) {
			$label = trans('formation::labels.update');
			if (is_bool($icon) && $icon)
				$icon = 'ok';
		} else {
			$label = trans('formation::labels.create');
			if (is_bool($icon) && $icon)
				$icon = 'plus';
		}

		//add icon code
		if (is_string($icon) && $icon != "")
			$label = '[ICON: '.$icon.']'.$label;

		if (!is_null($itemName) && $itemName != "")
			$label .= ' '.$itemName;

		return $label;
	}

	/**
	 * Get the status create / update status from the resource controller URL.
	 *
	 * @param  mixed   $route
	 * @return bool
	 */
	public function updateResource($route = null)
	{
		$route = $this->route($route);

		//set method based on route
		if (substr($route[0], -5) == ".edit")
			return true;
		else
			return false;
	}

	/**
	 * Parse the form action method.
	 *
	 * @param  string  $method
	 * @return string
	 */
	protected function getMethod($method)
	{
		$method = strtoupper($method);

		return $method != 'GET' ? 'POST' : $method;
	}

	/**
	 * Get the form action from the options.
	 *
	 * @param  array   $options
	 * @return string
	 */
	protected function getAction(array $options)
	{
		// We will also check for a "route" or "action" parameter on the array so that
		// developers can easily specify a route or controller action when creating
		// a form providing a convenient interface for creating the form actions.
		if (isset($options['url']))
		{
			return $this->getUrlAction($options['url']);
		}

		if (isset($options['route']))
		{
			return $this->getRouteAction($options['route']);
		}

		// If an action is available, we are attempting to open a form to a controller
		// action route. So, we will use the URL generator to get the path to these
		// actions and return them from the method. Otherwise, we'll use current.
		elseif (isset($options['action']))
		{
			return $this->getControllerAction($options['action']);
		}

		return $this->url->current();
	}

	/**
	 * Get the action for a "url" option.
	 *
	 * @param  array|string  $options
	 * @return string
	 */
	protected function getUrlAction($options)
	{
		if (is_array($options))
		{
			return $this->url->to($options[0], array_slice($options, 1));
		}

		return $this->url->to($options);
	}

	/**
	 * Get the action for a "route" option.
	 *
	 * @param  array|string  $options
	 * @return string
	 */
	protected function getRouteAction($options)
	{
		if (is_array($options))
		{
			return $this->url->route($options[0], array_slice($options, 1));
		}

		return $this->url->route($options);
	}

	/**
	 * Get the action for an "action" option.
	 *
	 * @param  array|string  $options
	 * @return string
	 */
	protected function getControllerAction($options)
	{
		if (is_array($options))
		{
			return $this->url->action($options[0], array_slice($options, 1));
		}

		return $this->url->action($options);
	}

	/**
	 * Get the form appendage for the given method.
	 *
	 * @param  string  $method
	 * @return string
	 */
	protected function getAppendage($method)
	{
		list($method, $appendage) = array(strtoupper($method), '');

		// If the HTTP method is in this list of spoofed methods, we will attach the
		// method spoofer hidden input to the form. This allows us to use regular
		// form to initiate PUT and DELETE requests in addition to the typical.
		if (in_array($method, $this->spoofedMethods))
		{
			$appendage .= $this->hidden('_method', ['value' => $method]);
		}

		// If the method is something other than GET we will go ahead and attach the
		// CSRF token to the form, as this can't hurt and is convenient to simply
		// always have available on every form the developers creates for them.
		if ($method != 'GET')
		{
			$appendage .= $this->token();
		}

		return $appendage;
	}

	/**
	 * Get the ID attribute for a field name.
	 *
	 * @param  string  $name
	 * @param  array   $attributes
	 * @return string
	 */
	public function getIdAttribute($name, $attributes)
	{
		if (array_key_exists('id', $attributes))
		{
			return $attributes['id'];
		}

		if (in_array($name, $this->labels))
		{
			return $name;
		}
	}

	/**
	 * Get the value that should be assigned to the field.
	 *
	 * @param  string  $name
	 * @param  string  $value
	 * @return string
	 */
	public function getValueAttribute($name, $value = null)
	{
		if (is_null($name)) return $value;

		if ( ! is_null($this->old($name)))
		{
			return $this->old($name);
		}

		if ( ! is_null($value)) return $value;

		if (isset($this->model))
		{
			return $this->getModelValueAttribute($name);
		}
	}

	/**
	 * Get the model value that should be assigned to the field.
	 *
	 * @param  string  $name
	 * @return string
	 */
	protected function getModelValueAttribute($name)
	{
		if (is_object($this->model))
		{
			return object_get($this->model, $this->transformKey($name));
		}
		elseif (is_array($this->model))
		{
			return array_get($this->model, $this->transformKey($name));
		}
	}

	/**
	 * Get a value from the session's old input.
	 *
	 * @param  string  $name
	 * @return string
	 */
	public function old($name)
	{
		if (isset($this->session))
		{
			return $this->session->getOldInput($this->transformKey($name));
		}
	}

	/**
	 * Determine if the old input is empty.
	 *
	 * @return bool
	 */
	public function oldInputIsEmpty()
	{
		return (isset($this->session) && count($this->session->getOldInput()) == 0);
	}

	/**
	 * Transform key from array to dot syntax.
	 *
	 * @param  string  $key
	 * @return string
	 */
	protected function transformKey($key)
	{
		return str_replace(array('.', '[]', '[', ']'), array('_', '', '.', ''), $key);
	}

	/**
	 * Get the session store implementation.
	 *
	 * @return  \Illuminate\Session\Store  $session
	 */
	public function getSessionStore()
	{
		return $this->session;
	}

	/**
	 * Set the session store implementation.
	 *
	 * @param  \Illuminate\Session\Store  $session
	 * @return $this
	 */
	public function setSessionStore(\Illuminate\Session\Store $session)
	{
		$this->session = $session;

		return $this;
	}

	/**
	 * Get the date format for populating date fields.
	 *
	 * @return string
	 */
	public function getDateFormat()
	{
		return config('form.format.date');
	}

	/**
	 * Get the date-time format for populating date-time fields.
	 *
	 * @return string
	 */
	public function getDateTimeFormat()
	{
		return config('form.format.datetime');
	}

	/**
	 * Get the application encoding.
	 *
	 * @return string
	 */
	protected function encoding()
	{
		return $this->encoding ?: $this->encoding = config('form.encoding');
	}

}