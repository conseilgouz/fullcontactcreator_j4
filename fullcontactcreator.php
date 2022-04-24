<?php
/**
 * @plugin  User.fullcontactcreator
 *
 * @copyright   Copyright (C) 2021 ConseilGouz. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 *
 * version 2.0.0
 */

defined('_JEXEC') or die;

use Joomla\String\StringHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Language\Text;
use Joomla\Component\Contact\Administrator\Table\ContactTable;
use Joomla\CMS\Plugin\PluginHelper;
/**
 * Class for Contact Creator
 *
 * A tool to automatically create and synchronise contacts with a user
 *
 */
class PlgUserFullContactCreator extends CMSPlugin
{
		/**
	 * Application Instance
	 *
	 * @var    \Joomla\CMS\Application\CMSApplication
	 * @since  4.0.0
	 */
	protected $app;

	/**
	 * Database Driver Instance
	 *
	 * @var    \Joomla\Database\DatabaseDriver
	 * @since  4.0.0
	 */
	protected $db;

	/**
	 * Utility method to act on a user after it has been saved.
	 *
	 * This method creates a contact for the saved user
	 *
	 * @param   array    $user     Holds the new user data.
	 * @param   boolean  $isnew    True if a new user is stored.
	 * @param   boolean  $success  True if user was succesfully stored in the database.
	 * @param   string   $msg      Message.
	 *
	 * @return  boolean
	 *
	 * @since   1.6
	 */
	public function onUserAfterSave($user, $isnew, $success, $msg)
	{
		// If the user wasn't stored we don't resync
		if (!$success)
		{
			return false;
		}
		// If the user isn't new we don't sync
		if (!$isnew)
		{
			return false;
		}
		// Ensure the user id is really an int
		$user_id = (int) $user['id'];
		// If the user id appears invalid then bail out just in case
		if (empty($user_id))
		{
			return false;
		}
		$categoryId = $this->params->get('category', 0);
		if (empty($categoryId))
		{
			Factory::getApplication()->enqueueMessage(Text::_('PLG_CONTACTCREATOR_ERR_NO_CATEGORY'),'warning');
			return false;
		}
		if ($contact = $this->getContactTable())
		{
			/**
			 * Try to pre-load a contact for this user. Apparently only possible if other plugin creates it
			 * Note: $user_id is cleaned above
			 */
			if (!$contact->load(array('user_id' => (int) $user_id)))
			{
				$contact->published = $this->params->get('autopublish', 0);
			}
			$contact->name     = $user['name'];
			$contact->user_id  = $user_id;
			$contact->email_to = $user['email'];
			$contact->catid    = $categoryId;
			$contact->access   = (int) Factory::getConfig()->get('access');
			$contact->language = '*';
			$contact->generateAlias();
			// Check if the contact already exists to generate new name & alias if required
			if ($contact->id == 0)
			{
				list($name, $alias) = $this->generateAliasAndName($contact->alias, $contact->name, $categoryId);
				$contact->name  = $name;
				$contact->alias = $alias;
			}
			$this->insertProfileData($user_id,$contact); // insert user profile data
			if ($contact->check() && $contact->store())
			{
				$this->insertFiels($user_id,$contact->id); // insert user fields
				return true;
			}
		}
		JFactory::getApplication()->enqueueMessage(JText::_('PLG_CONTACTCREATOR_ERR_FAILED_CREATING_CONTACT'),'warning');
		return false;
	}
	// Merge the profile data.
	private function insertProfileData($user_id,&$contact) {
		$db = $this->db;
		$query = $db->getQuery(true)
			->select($db->quoteName(array('profile_key', 'profile_value')))
			->from($db->quoteName('#__user_profiles'))
			->where($db->quoteName('user_id').' = '.(int)$user_id.' AND profile_key LIKE "profile%"')
			->order('ordering ASC');
			$db->setQuery($query);
		try
		{
		    $results = $db->loadRowList();
		}
		catch (\RuntimeException $e)
		{
		    $this->_subject->setError($e->getMessage());
		    return false;
		}
		$profile = array();
		foreach ($results as $v)
		{
		    $k = str_replace('profile.', '', $v[0]);
			$k = str_replace('profilep.', '', $k);
		    $profile[$k] = json_decode($v[1], true);
		    if ($profile[$k] === null)
		    {
		        $profile[$k] = $v[1];
		    }
		}
		$contact->address = $profile['address1'].' '.$profile['address2'];
		$contact->suburb = $profile['city'];
		$contact->state = $profile['region'];
		$contact->country = $profile['country'];
		$contact->postcode = $profile['postal_code'];
		$contact->telephone = $profile['phone'];
		$contact->webpage = $profile['website'];
		$contact->misc = $profile['favoritebook'];
		if (PluginHelper::isEnabled('user', 'profilep')) {
			$contact->con_position = $profile['position'];
		}
		return true;
	}
		
	// create contact fields from user fields
	// link between contact fields and user fields is handled by note field
	//
	protected function insertFiels($user_id,$contact_id) {
		$db = $this->db;
		$query = $db->getQuery(true);
		$query->select('v.value as value, f2.id as id')
			->from('#__fields f ')
			->join('LEFT','#__fields f2 on f.note = f2.note and f2.context = "com_contact.contact"')
			->join('LEFT','#__fields_values v on v.field_id = f.id')
			->where('v.item_id = '.$user_id.' and f.context = "com_users.user" and f.state > 0 and f2.state > 0');
		$db->setQuery($query);
		try
			{
			$results = $db->loadRowList();
			}
		catch (\RuntimeException $e)
		{
		    $this->_subject->setError($e->getMessage());
		    return false;
		}
		foreach ($results as $v) {
			$query = $db->getQuery(true);
			$columns = array("field_id","item_id","value");
			$values=array($v[1],$contact_id,$db->quote($v[0]));
			$query
				->insert($db->quoteName('#__fields_values'))
				->columns($db->quoteName($columns))
				->values(implode(',', $values));
			$db->setQuery($query);
			$db->execute();					
		}
		return true;	
	}
	
	/**
	 * Method to change the name & alias if alias is already in use
	 *
	 * @param   string   $alias       The alias.
	 * @param   string   $name        The name.
	 * @param   integer  $categoryId  Category identifier
	 *
	 * @return  array  Contains the modified title and alias.
	 *
	 */
	protected function generateAliasAndName($alias, $name, $categoryId)
	{
		$table = $this->getContactTable();

		while ($table->load(array('alias' => $alias, 'catid' => $categoryId)))
		{
			if ($name === $table->name)
			{
				$name = StringHelper::increment($name);
			}

			$alias = StringHelper::increment($alias, 'dash');
		}

		return array($name, $alias);
	}

	/**
	 * Get an instance of the contact table
	 *
	 * @return  ContactTableContact
	 *
	 */
	protected function getContactTable()
	{
		
		return $this->app->bootComponent('com_contact')->getMVCFactory()->createTable('Contact', 'Administrator', ['dbo' => $this->db]);
	}
}
