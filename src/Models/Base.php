<?php namespace Regulus\Formation\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Input;

use Regulus\Formation\Facade as Form;
use Regulus\TetraText\Facade as Format;

class Base extends Model {

	/**
	 * The special typed fields for the model.
	 *
	 * @var    array
	 */
	protected $types = [];

	/**
	 * The special formatted fields for the model.
	 *
	 * @var    array
	 */
	protected $formats = [];

	/**
	 * The special formatted fields for the model for saving to the database.
	 *
	 * @var    array
	 */
	protected $formatsForDb = [];

	/**
	 * The foreign key for the model.
	 *
	 * @var    string
	 */
	protected $foreignKey = null;

	/**
	 * Get the default foreign key name for the model.
	 *
	 * @return string
	 */
	public function getForeignKey()
	{
		if (!is_null($this->foreignKey))
			return $this->foreignKey;
		else
			return snake_case(class_basename($this)).'_id';
	}

	/**
	 * Get the special typed fields for the model.
	 *
	 * @return array
	 */
	public function getFieldTypes()
	{
		return $this->types;
	}

	/**
	 * Get the special formatted values for the model.
	 *
	 * @return array
	 */
	public function getFieldFormats()
	{
		return $this->formats;
	}

	/**
	 * Get the special formatted values for DB insertion for the model.
	 *
	 * @return array
	 */
	public function getFieldFormatsForDb()
	{
		return $this->formatsForDb;
	}

	/**
	 * The default values for the model.
	 *
	 * @return array
	 */
	public static function defaults()
	{
		return [];
	}

	/**
	 * Get the validation rules used by the model.
	 *
	 * @param  mixed    $id
	 * @return array
	 */
	public static function validationRules($id = null)
	{
		return [];
	}

	/**
	 * Set the validation rules for the model for a new record.
	 *
	 * @return void
	 */
	public static function setValidationRulesForNew()
	{
		Form::setValidationRules(static::validationRules());
	}

	/**
	 * Set the validation rules for the model.
	 *
	 * @return void
	 */
	public function setValidationRules()
	{
		Form::setValidationRules(static::validationRules((int) $this->id));
	}

	/**
	 * Get the formatted values for populating a form.
	 *
	 * @param  array    $relations
	 * @return object
	 */
	public function getFormattedValues($relations = [])
	{
		// format fields based on field types
		foreach ($this->getFieldTypes() as $field => $type)
		{
			if (isset($this->{$field}))
			{
				$value = $this->{$field};
				$this->{$field} = static::formatValue($value, $type);
			}
		}

		// format fields based on special format rules
		foreach ($this->getFieldFormats() as $field => $formats)
		{
			$fieldTested = isset($format[1]) ? $format[1] : $field;
			$valueTested = $this->{$fieldTested} ? isset($this->{$field}) : null;

			$this->{$field} = $this->formatValueFromSpecialFormats($field, $this->toArray(), $formats);
		}

		foreach ($relations as $relation)
		{
			if ($this->{$relation})
			{
				foreach ($this->{$relation} as &$item)
				{
					foreach ($item->getFieldTypes() as $field => $type)
					{
						if (isset($item->{$field}))
						{
							$value = $item->{$field};
							$item->{$field.'_formatted'} = static::formatValue($value, $type);
						}
					}

					foreach ($item->getFieldFormats() as $field => $formats)
					{
						if (isset($this->{$field}))
							$this->{$field} = $this->formatValueFromSpecialFormats($this->{$field}, $formats);
					}
				}
			}
		}

		return $this;
	}

	/**
	 * Get the formatted values for populating a form.
	 *
	 * @param  string   $value
	 * @param  string   $type
	 * @return string
	 */
	private static function formatValue($value, $type)
	{
		switch ($type)
		{
			case "date":      $value = ($value != "0000-00-00" && $value != "" && !is_null($value) ? date(Form::getDateFormat(), strtotime($value)) : ""); break;
			case "date-time": $value = ($value != "0000-00-00 00:00:00" && $value != "" && !is_null($value) ? date(Form::getDateTimeFormat(), strtotime($value)) : ""); break;
		}

		return $value;
	}

	/**
	 * Set the default values for the model for a new item.
	 *
	 * @param  mixed    $prefix
	 * @return array
	 */
	public static function setDefaultsForNew($prefix = null)
	{
		return Form::setDefaults(static::defaults(), [], $prefix);
	}

	/**
	 * Add a prefix to the default values if one is set.
	 *
	 * @param  array    $defaults
	 * @param  mixed    $prefix
	 * @return array
	 */
	public static function addPrefixToDefaults($defaults = [], $prefix = null)
	{
		if (is_string($prefix) && $prefix != "")
			$prefix .= ".";

		$defaultsFormatted = [];
		foreach ($defaults as $field => $value) {
			$defaultsFormatted[$prefix.$field] = $value;
		}

		return $defaultsFormatted;
	}

	/**
	 * Set the default values for the model.
	 *
	 * @param  array    $relations
	 * @param  mixed    $prefix
	 * @return array
	 */
	public function setDefaults($relations = [], $prefix = null)
	{
		return Form::setDefaults($this->getFormattedValues($relations), $relations, $prefix);
	}

	/**
	 * Save the input data to the model.
	 *
	 * @param  mixed    $input
	 * @param  boolean  $new
	 * @return void
	 */
	public function saveData($input = null, $new = false)
	{
		if (is_null($input))
			$input = Input::all();

		// format data for special types and special formats
		$input = $this->formatValuesForDb($input, $new);

		$this->fill($input)->save();

		foreach ($input as $field => $value)
		{
			if (is_array($value))
			{
				$fieldCamelCase = Form::underscoredToCamelCase($field);

				if (isset($this->{$fieldCamelCase}) || $this->{$fieldCamelCase})
					$this->saveRelationalData($value, $fieldCamelCase);
			}
		}
	}

	/**
	 * Save the relational input data to the model.
	 *
	 * @param  mixed    $input
	 * @param  string   $modelMethod
	 * @return void
	 */
	public function saveRelationalData($input, $modelMethod)
	{
		$idsSaved        = [];
		$items           = $this->{$modelMethod};
		$model           = get_class($this->{$modelMethod}()->getModel());
		$formattedSuffix = Form::getFormattedFieldSuffix();
		$pivotTimestamps = config('form.pivot_timestamps');

		// create or update related items
		foreach ($input as $index => $itemData)
		{
			if ($index != "pivot")
			{
				if (isset($itemData['id']) && $itemData['id'] > 0 && $itemData['id'] != "")
					$new = false;
				else
					$new = true;

				$found = false;
				if (!$new)
				{
					foreach ($items as $item)
					{
						if ((int) $itemData['id'] == (int) $item->id)
						{
							$found = true;

							// remove formatted fields from item to prevent errors in saving data
							foreach ($item->toArray() as $field => $value)
							{
								if (substr($field, -(strlen($formattedSuffix))) == $formattedSuffix)
									unset($item->{$field});
							}

							// format data for special types and special formats
							$itemData = $item->formatValuesForTypes($itemData);
							$itemData = $item->formatValuesForSpecialFormats($itemData);

							// save data
							$item->fill($itemData)->save();

							$currentItem = $item;

							if (!in_array((int) $item->id, $idsSaved))
								$idsSaved[] = (int) $item->id;
						}
					}
				}

				// if model was not found, it may still exist in the database but not have a current relationship with item
				if (!$found && !$new)
				{
					$item = $model::find($itemData['id']);

					if ($item)
					{
						// format data for special types
						$itemData = $item->formatValuesForTypes($itemData);

						// save data
						$item->fill($itemData)->save();

						$currentItem = $item;

						if (!in_array((int) $item->id, $idsSaved))
							$idsSaved[] = (int) $item->id;
					}
					else
					{
						$new = true;
					}
				}

				if ($new)
				{
					$item = new $model;

					// attempt to add foreign key ID in case relationship doesn't require a pivot table
					$itemData[$this->getForeignKey()] = $this->id;

					// format data for special types
					$itemData = $item->formatValuesForTypes($itemData);

					$item->fill($itemData)->save();

					$currentItem = $item;

					if (!in_array((int) $item->id, $idsSaved))
						$idsSaved[] = (int) $item->id;
				}

				// save pivot data
				if (isset($itemData['pivot']))
				{
					$pivotTable = $this->{$modelMethod}()->getTable();
					$pivotKeys  = [
						$this->getForeignKey() => $this->id,
						$item->getForeignKey() => $currentItem->id,
					];

					$pivotData = array_merge($itemData['pivot'], $pivotKeys);

					// set updated timestamp
					if ($pivotTimestamps)
					{
						$timestamp = date('Y-m-d H:i:s');
						$pivotData['updated_at'] = $timestamp;
					}

					// attempt to select pivot record by both keys
					$pivotItem = DB::table($pivotTable);
					foreach ($pivotKeys as $key => $id)
					{
						$pivotItem->where($key, $id);
					}

					// if id exists, add it to where clause and unset it
					if (isset($pivotData['id']) && (int) $pivotData['id'])
					{
						$pivotItem->where('id', $pivotData['id']);
						unset($pivotData['id']);
					}

					// attempt to update and if it doesn't work, insert a new record
					if (!$pivotItem->update($pivotData))
					{
						if ($pivotTimestamps)
							$pivotData['created_at'] = $timestamp;

						DB::table($pivotTable)->insert($pivotData);
					}
				}
			} else {
				// data is entirely pivot data; save pivot data

				$item = new $model; //create dummy item to get foreign key

				$pivotTable    = $this->{$modelMethod}()->getTable();
				$pivotIdsSaved = [];

				foreach ($itemData as $pivotId)
				{
					$pivotKeys  = [
						$this->getForeignKey() => $this->id,
						$item->getForeignKey() => $pivotId,
					];

					$pivotData = $pivotKeys;

					// set updated timestamp
					if ($pivotTimestamps)
					{
						$timestamp = date('Y-m-d H:i:s');
						$pivotData['updated_at'] = $timestamp;
					}

					// attempt to select pivot record by both keys
					$pivotItem = DB::table($pivotTable);
					foreach ($pivotKeys as $key => $id)
					{
						$pivotItem->where($key, $id);
					}

					// if id exists, add it to where clause and unset it
					if (isset($pivotData['id']) && (int) $pivotData['id'])
					{
						$pivotItem->where('id', $pivotData['id']);
						unset($pivotData['id']);
					}

					// attempt to update and if it doesn't work, insert a new record
					if (!$pivotItem->update($pivotData))
					{
						if ($pivotTimestamps)
							$pivotData['created_at'] = $timestamp;

						DB::table($pivotTable)->insert($pivotData);
					}

					if (!in_array((int) $pivotId, $pivotIdsSaved))
						$pivotIdsSaved[] = (int) $pivotId;
				}

				if (empty($pivotIdsSaved))
					DB::table($pivotTable)
						->where($this->getForeignKey(), $this->id)
						->delete();
				else
					DB::table($pivotTable)
						->where($this->getForeignKey(), $this->id)
						->whereNotIn($item->getForeignKey(), $pivotIdsSaved)
						->delete();
			}
		}

		// remove any items no longer present in input data
		if ($index != "pivot")
		{
			foreach ($items as $item)
			{
				if (!in_array((int) $item->id, $idsSaved))
				{
					// check for pivot data and delete pivot item instead of item if it exists
					if (isset($itemData['pivot']))
					{
						DB::table($pivotTable)
							->where($this->getForeignKey(), $this->id)
							->where($item->getForeignKey(), $item->id)
							->delete();
					}
					else
					{
						$item->delete();
					}
				}
			}
		}
	}

	/**
	 * Format values for insertion into database.
	 *
	 * @param  array    $values
	 * @param  boolean  $new
	 * @return array
	 */
	public function formatValuesForDb($values, $new = false)
	{
		$values = $this->formatValuesForTypes($values);
		$values = $this->formatValuesForSpecialFormats($values);
		$values = $this->formatValuesForModel($values, $new);

		return $values;
	}

	/**
	 * Fill model with formatted values.
	 *
	 * @param  array    $values
	 * @return void
	 */
	public function fillFormattedValues($values)
	{
		$values = $this->formatValuesForDb($values);
		$this->fill($values);
	}

	/**
	 * Format values based on the model's special field types for data insertion into database.
	 *
	 * @param  array    $values
	 * @return array
	 */
	public function formatValuesForTypes($values)
	{
		foreach ($this->getFieldTypes() as $field => $type)
		{
			$value      = isset($values[$field]) ? $values[$field] : null;
			$unsetValue = false;

			switch ($type)
			{
				case "checkbox":
					$value = (!is_null($value) && $value != false); break;

				case "date":
					$value = (!is_null($value) && $value != "" ? date('Y-m-d', strtotime($value)) : null); break;

				case "date-time":
				case "datetime":
				case "timestamp":
					$value = (!is_null($value) && $value != "" ? date('Y-m-d H:i:s', strtotime($value)) : null); break;

				case "date-not-null":
					$value = (!is_null($value) && $value != "" ? date('Y-m-d', strtotime($value)) : "0000-00-00"); break;

				case "date-time-not-null":
				case "datetime-not-null":
				case "timestamp-not-null":
					$value = (!is_null($value) && $value != "" ? date('Y-m-d H:i:s', strtotime($value)) : "0000-00-00 00:00:00"); break;

				case "slug":
					$value = Format::slug($value); break;

				case "unique-slug":
					$value = Format::uniqueSlug($value, $this->table, $field, $this->id); break;
			}

			// set a timestamp based on the checked status of a checkbox
			if (in_array(substr($type, 0, 18), ['checkbox-timestamp', 'checkbox-date-time', 'checkbox-datetime']))
			{
				$value = (!is_null($value) && $value != false);

				$typeArray = explode(':', $type);

				// set checkbox value in case checkbox field actually exists in model
				$values[$field] = (bool) $value;

				// set field name for timestamp field
				if (count($typeArray) == 1)
					$field .= "_at";
				else
					$field = $typeArray[1];

				// set timestamp based on checkbox status and previous timestamp value
				if ($value)
				{
					if (is_null($this->{$field}))
						$value = date('Y-m-d H:i:s');
					else
						$unsetValue = true;
				}
				else
				{
					$value = null;
				}
			}

			if (!$unsetValue)
			{
				$values[$field] = $value;
			}
			else
			{
				if (isset($values[$field]))
					unset($values[$field]);
			}
		}

		return $values;
	}

	/**
	 * Format values based on the model's special field types for data insertion into database.
	 *
	 * @param  array    $values
	 * @return array
	 */
	public function formatValuesForSpecialFormats($values)
	{
		// automatically trim field values if config set
		if (config('form.auto_trim'))
		{
			foreach ($values as $field => $value)
			{
				if (is_string($value))
					$values[$field] = $this->trimValue($value);
			}
		}

		foreach ($this->getFieldFormatsForDb() as $field => $formats)
		{
			if (isset($values[$field]))
			{
				$values[$field] = $this->formatValueFromSpecialFormats($field, $values, $formats);
			}
			else
			{
				if (is_string($formats))
					$formats = [$formats];

				if (in_array('blank-if-not-set', $formats))
					$values[$field] = "";

				if (in_array('null-if-not-set', $formats))
					$values[$field] = null;

				if (in_array('pivot-array', $formats))
					$values[$field] = ['pivot' => []];
			}
		}

		return $values;
	}

	/**
	 * Format values based on the model's special formats for data insertion into database.
	 *
	 * @param  string   $field
	 * @param  array    $values
	 * @param  array    $formats
	 * @return array
	 */
	public function formatValueFromSpecialFormats($field, $values, $formats)
	{
		if (is_object($values))
			$value = isset($values->{$field}) ? $values->{$field} : null;
		else
			$value = isset($values[$field]) ? $values[$field] : null;

		if (is_string($formats))
			$formats = [$formats];

		foreach ($formats as $format)
		{
			$format = explode(':', $format);

			$fieldTested = isset($format[1]) && $format[1] != "" ? $format[1] : $field;
			$valueTested = isset($values[$fieldTested]) ? $values[$fieldTested] : null;

			switch ($format[0])
			{
				case "false-if-null":
					if (is_null($valueTested))
						$value = false;

					break;

				case "true-if-null":
					if (is_null($valueTested))
						$value = true;

					break;

				case "false-if-not-null":
					if (!is_null($valueTested))
						$value = false;

					break;

				case "true-if-not-null":
					if (!is_null($valueTested))
						$value = true;

					break;

				case "false-if-blank":
					if ($valueTested == "" || $valueTested == "0000-00-00" || $valueTested == "0000-00-00 00:00:00")
						$value = false;

					break;

				case "true-if-blank":
					if ($valueTested == "" || $valueTested == "0000-00-00" || $valueTested == "0000-00-00 00:00:00")
						$value = true;

					break;

				case "null-if-blank":
					if ($valueTested == "" || $valueTested == "0000-00-00" || $valueTested == "0000-00-00 00:00:00")
						$value = null;

					break;

				case "false-if-not-blank":
					if ($valueTested != "")
						$value = false;

					break;

				case "true-if-not-blank":
					if ($valueTested != "")
						$value = true;

					break;

				case "null-if-not-blank":
					if ($valueTested != "")
						$value = null;

					break;

				case "json":
					$value = json_encode($value);

					break;

				case "json-or-null":
					if (is_null($value) || empty($value))
						$value = null;
					else
						$value = json_encode($value);

					break;

				case "trim":
					$value = $this->trimValue($value);

					break;

				case "uppercase-first":
					$value = ucfirst(trim($value));

					break;

				case "uppercase-words":
					$value = ucwords(trim($value));

					break;

				case "uppercase":
					$value = strtoupper($value);

					break;

				case "lowercase":
					$value = strtolower($value);

					break;
			}
		}

		return $value;
	}

	/**
	 * Trim a value or array of values.
	 *
	 * @param  mixed    $value
	 * @return array
	 */
	private function trimValue($value)
	{
		if (is_array($value))
		{
			foreach ($value as $v => $subValue)
			{
				if (is_string($subValue))
					$value[$v] = trim($subValue);
			}
		}
		else
		{
			$value = trim($value);
		}

		return $value;
	}

	/**
	 * Custom formatting method for a specific model. This function exists to be extended by a specific model to allow
	 * custom formatting before data is inserted into the database.
	 *
	 * @param  array    $values
	 * @param  boolean  $new
	 * @return array
	 */
	public function formatValuesForModel($values, $new = false)
	{
		return $values;
	}

	/**
	 * Create a model item and save the input data to the model.
	 *
	 * @param  mixed    $input
	 * @return object
	 */
	public static function createNew($input = null)
	{
		$item = static::create();
		$item->saveData($input, $item->id);

		return $item;
	}

	/**
	 * Gets the model by a field other than its ID.
	 *
	 * @param  string   $field
	 * @param  string   $value
	 * @param  mixed    $relations
	 * @param  boolean  $returnQuery
	 * @return object
	 */
	public static function findBy($field = 'slug', $value, $relations = [], $returnQuery = false)
	{
		$item = new static;

		if (is_null($relations) || is_bool($relations))
		{
			//if relations is boolean, assume it to be returnQuery instead
			if (is_bool($relations))
				$returnQuery = $relations;

			$relations = [];
		}

		$item = $item->where($field, $value);

		foreach ($relations as $relation)
		{
			$item = $item->with($relation);
		}

		if (!$returnQuery)
			$item = $item->first();

		return $item;
	}

	/**
	 * Gets the model by its slug.
	 *
	 * @param  string   $slug
	 * @param  mixed    $relations
	 * @param  boolean  $returnQuery
	 * @return object
	 */
	public static function findBySlug($slug, $relations = [], $returnQuery = false)
	{
		return static::findBy('slug', $slug, $relations, $returnQuery);
	}

	/**
	 * Gets the model by its slug, stripped of dashes.
	 *
	 * @param  string   $slug
	 * @param  mixed    $relations
	 * @param  boolean  $returnQuery
	 * @return object
	 */
	public static function findByDashlessSlug($slug, $relations = [], $returnQuery = false)
	{
		return static::findBy(DB::raw('replace(slug, \'-\', \'\')'), str_replace('-', '', $slug), $relations, $returnQuery);
	}

}