<?php
/*------------------------------------------------------------------------
# plg_system_switchuser - Switch User Plugin
# ------------------------------------------------------------------------
# author Artd Webdesign GmbH
# copyright Copyright (C) artd.ch. All Rights Reserved.
# @license - http://www.gnu.org/licenses/gpl-2.0.html GNU/GPL
# Websites: http://www.artd.ch
# Technical Support:  http://www.artd.ch
-------------------------------------------------------------------------*/
// no direct access
defined( '_JEXEC' ) or die( 'Restricted access' );

use Joomla\CMS\Table\Table;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Factory;
use Joomla\CMS\Utility\Utility;
use Joomla\CMS\Object\CMSObject;
use Joomla\CMS\Session\Session;


class plgSystemSwitchUser extends CMSPlugin
{
	function __construct(&$subject, $config = array())
	{
		parent::__construct($subject, $config);
		CMSPlugin::loadLanguage('plg_system_switchuser', JPATH_ADMINISTRATOR);
	}
	
	function onAfterDispatch()
	{
		$app = Factory::getApplication();
		$option = $app->input->get('option', '');
		$task = $app->input->get('view', $option == 'com_users' ? 'view' : '');
		
		if (!$app->isClient('administrator') || $option != 'com_users' || $task != 'users') {
			return;
		}

		$admin	= Factory::getUser();
		$document = Factory::getDocument();
		$content = $document->getBuffer('component');

		$pattern = '/name="cid\[\]" value="(\d+)"/';

		if (!preg_match_all($pattern, $content, $matches)) {

			return;
		}

		$userIds = $matches[1];
		$db = version_compare(\Joomla\CMS\Version::MAJOR_VERSION, "4", ">=") ? Factory::getContainer()->get('DatabaseDriver') : Factory::getDbo();
		$query = $db->getQuery(true);
		$query 
		->select('id, username, name')
		->from('#__users')
		->where('id IN ('.implode(',', $userIds).')');
		$db->setQuery($query);
		$users = $db->loadObjectList('id');
		
		$patterns = array();
		$replacements = array();
		foreach ($users as $userId => $user) {
			$patterns[] = '|<a href="'.addcslashes(Route::_('index.php?option=com_users&amp;task=user.edit&amp;id='.(int)$userId), '?').'"[^>]+>\s*'.$user->name.'\s*</a>|';
			$replacements[] = '${0} <a href="'.JURI::root().'index.php?option=com_users&switchuser=1&uid='.$userId.'&aid='.$admin->id.'" target="_blank" title="'.Text::sprintf('SWITCHUSER_FRONT_END', $user->username).'"><img style="margin: 0 10px;" src="'.JURI::root().'plugins/system/switchuser/switchuser/images/frontend-login.png" alt="'.Text::_('SWITCHUSER_FRONT_END').'" /></a>';
		}
		$content = preg_replace($patterns, $replacements, $content);
		$document->setBuffer($content, 'component');
	}
	function onAfterInitialise()
	{
		$app	= Factory::getApplication();
		$db		= version_compare(\Joomla\CMS\Version::MAJOR_VERSION, "4", ">=") ? Factory::getContainer()->get('DatabaseDriver') : Factory::getDbo();
		$query 	= $db->getQuery(true);
		$user	= Factory::getUser();
		$userId = $app->input->get('uid', 0, 'int');
		$backendUserId = $app->input->get('aid', 0, 'int');
		
		if ($app->isClient('administrator') == true || $app->input->get('switchuser', false, 'bool') == false || !$userId) {
			return;
		}

		if ($user->id == $userId) {
			$app->enqueueMessage(Text::_('SWITCHUSER_YOU_HAVE_ALREADY_LOGIN_AS_THIS_USER'), 'error');
			return $app->redirect('index.php');
		}
		
		if (!empty($user->id)) {
			$app->enqueueMessage(Text::_('SWITCHUSER_YOU_HAVE_LOGIN_LOGOUT_FIRST'), 'error');
			return $app->redirect('index.php');
		}
echo "Hash is: ".JApplication::getHash('administrator')."<br>";
echo "MD% is: ".md5(JApplication::getHash('administrator'))."<br>";		
		$backendSessionId = $app->input->get(md5(JApplication::getHash('administrator')), null ,"COOKIE");

		$query 
			->select('userid')
			->from('#__session')
			->where('session_id = '.$db->Quote($backendSessionId))
			->where('client_id = 1')
			->where('guest = 0');
		$db->setQuery($query);
echo "DB User ID is: ".$db->loadResult()."<br>"; die;
		if (empty($backendUserId = $db->loadResult())) {
			$app->enqueueMessage(Text::_('SWITCHUSER_BACKEND_USER_SESSION_EXPIRED'), 'error');
			return $app->redirect('index.php');
		}
		
		try {
			$instance = Factory::getUser($userId);
		} catch (Exception $e) {
			$app->enqueueMessage(sprint(Text::_('SWITCHUSER_YOU_GETUSER_FAILED'), $e->getMessage()), 'error');
			$app->redirect('index.php');
			return;
		}

		// If the user is blocked, redirect with an error
		if ($instance->get('block') == 1) {
			$app->enqueueMessage(Text::_('E_NOLOGIN_BLOCKED'), 'error');
			$app->redirect('index.php');
			return;
		}

		// Get an ACL object
		$acl = Factory::getACL();

		// Get the user group from the ACL
		if ($instance->get('tmp_user') == 1) {
			$grp = new CMSObject;
			// This should be configurable at some point
			$grp->set('name', 'Registered');
		} else {
			//$grp = $acl->getAroGroup($instance->get('id'));
		}

		//Authorise the user based on the group information
		if(!isset($options['group'])) {
			$options['group'] = 'USERS';
		}

		//Mark the user as logged in
		$instance->set( 'guest', 0);
		$instance->set('aid', 1);

		//Set the usertype based on the ACL group name
		$instance->set('usertype', $grp->name);

		// Register the needed session variables
		$session = Factory::getSession();
		$session->set('user', $instance);

		// Get the session object
		$table = Table::getInstance('session');
		$table->load( $session->getId() );

		$table->guest 		= $instance->get('guest');
		$table->username 	= $instance->get('username');
		$table->userid 		= intval($instance->get('id'));
		$table->usertype 	= $instance->get('usertype');

		$table->update();

		// Hit the user last visit field
		$instance->setLastVisit();
		$app->enqueueMessage(sprintf(Text::_('SWITCHUSER_YOU_HAVE_LOGIN_SUCCESSFULLY'), Factory::getUser($instance->get('id'))->name), 'info');
		$app->redirect('index.php');
	}
}