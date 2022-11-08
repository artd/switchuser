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
use Joomla\CMS\Application\ApplicationHelper;


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

		$backendSessionId = $app->input->cookie->get(md5(ApplicationHelper::getHash('administrator')));

		$query 
			->select('userid')
			->from('#__session')
			->where('session_id = '.$db->Quote($backendSessionId))
			->where('client_id = 1')
			->where('guest = 0');
		$db->setQuery($query);

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
		// Hit the user last visit field
		$instance->setLastVisit();

		// Register the needed session variables
		$session = Factory::getSession();
		$session->set('user', $instance);

		$app->enqueueMessage(sprintf(Text::_('SWITCHUSER_YOU_HAVE_LOGIN_SUCCESSFULLY'), Factory::getUser($instance->get('id'))->name), 'info');
		$app->redirect('index.php');
	}
}