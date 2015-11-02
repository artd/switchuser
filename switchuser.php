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


class plgSystemSwitchUser extends JPlugin
{
	function __construct(&$subject, $config = array())
	{
		parent::__construct($subject, $config);
		JPlugin::loadLanguage('plg_system_switchuser', JPATH_ADMINISTRATOR);
	}
	
	function onAfterDispatch()
	{
		$app =& JFactory::getApplication();
		$option = JRequest::getCmd('option', '');
		$task = JRequest::getCmd('view', $option == 'com_users' ? 'view' : '');
		
		//if (!$app->isAdmin() || $option != 'com_users' || $task != 'view') {
		if (!$app->isAdmin() || $option != 'com_users' || $task != 'users') {
			return;
		}

		$document =& JFactory::getDocument();
		$content = $document->getBuffer('component');

		$pattern = '/name="cid\[\]" value="(\d+)"/';

		if (!preg_match_all($pattern, $content, $matches)) {

			return;
		}
		$userIds = $matches[1];
		$db =& JFactory::getDbo();
		$query = 'SELECT id, username, name'
			. ' FROM #__users'
			. ' WHERE id IN ('.implode(',', $userIds).')'
		;
		$db->setQuery($query);
		$users = $db->loadObjectList('id');

		
		
		$patterns = array();
		$replacements = array();
		foreach ($users as $userId => $user) {
			
			//$patterns[] = '|<a href="'.addcslashes(JRoute::_('index.php?option=com_users&amp;view=user&amp;task=edit&amp;cid[]='.(int)$userId), '?').'"[^>]+>\s*'.$user->name.'\s*</a>|';
			//$replacements[] = '${0} <a href="'.JURI::root().'index.php?switchuser=1&uid='.$userId.'" target="_blank" title="'.JText::sprintf('SWITCHUSER_FRONT_END', $user->username).'"><img style="margin: 0 10px;" src="'.JURI::root().'plugins/system/switchuser/switchuser/images/frontend-login.png" alt="'.JText::_('SWITCHUSER_FRONT_END').'" /></a>';
			$patterns[] = '|<a href="'.addcslashes(JRoute::_('index.php?option=com_users&amp;task=user.edit&amp;id='.(int)$userId), '?').'"[^>]+>\s*'.$user->name.'\s*</a>|';
			$replacements[] = '${0} <a href="'.JURI::root().'index.php?option=com_users&switchuser=1&uid='.$userId.'" target="_blank" title="'.JText::sprintf('SWITCHUSER_FRONT_END', $user->username).'"><img style="margin: 0 10px;" src="'.JURI::root().'plugins/system/switchuser/switchuser/images/frontend-login.png" alt="'.JText::_('SWITCHUSER_FRONT_END').'" /></a>';
		}
		$content = preg_replace($patterns, $replacements, $content);
		$document->setBuffer($content, 'component');
	}
	function onAfterInitialise()
	{
		$app	=& JFactory::getApplication();
		$db		=& JFactory::getDbo();
		$user	=& JFactory::getUser();
		$userId = JRequest::getInt('uid', 0);
		
		if ($app->isAdmin() || !JRequest::getBool('switchuser', 0) || !$userId) {
			return;
		}
		
		if ($user->id == $userId) {
			return $app->redirect('index.php', JText::_('SWITCHUSER_YOU_HAVE_ALREADY_LOGIN_AS_THIS_USER'));
		}
		
		if ($user->id) {
			JError::raiseWarning('SOME_ERROR_CODE', JText::_('SWITCHUSER_YOU_HAVE_LOGIN_LOGOUT_FIRST'));
			return $app->redirect('index.php');
		}
		
		//$backendSessionId = JRequest::getVar(md5(JUtility::getHash('administrator')), null ,"COOKIE");

		
		$backendSessionId = JRequest::getVar(md5(JApplication::getHash('administrator')), null ,"COOKIE");


		$query = 'SELECT userid'
			. ' FROM #__session'
			. ' WHERE session_id = '.$db->Quote($backendSessionId)
			. ' AND client_id = 1'
			. ' AND guest = 0'
		;
		$db->setQuery($query);
		if (!$backendUserId = $db->loadResult()) {
			JError::raiseWarning('SOME_ERROR_CODE', JText::_('SWITCHUSER_BACKEND_USER_SESSION_EXPIRED'));
			return $app->redirect('index.php');
		}
		
		$instance =& JFactory::getUser($userId);

		// If _getUser returned an error, then pass it back.
		if (JError::isError($instance)) {
			$app->redirect('index.php');
			return;
		}

		// If the user is blocked, redirect with an error
		if ($instance->get('block') == 1) {
			JError::raiseWarning('SOME_ERROR_CODE', JText::_('E_NOLOGIN_BLOCKED'));
			$app->redirect('index.php');
			return;
		}

		// Get an ACL object
		$acl =& JFactory::getACL();

		// Get the user group from the ACL
		if ($instance->get('tmp_user') == 1) {
			$grp = new JObject;
			// This should be configurable at some point
			$grp->set('name', 'Registered');
		} else {
			//$grp = $acl->getAroGroup($instance->get('id'));
		}

		//Authorise the user based on the group information
		if(!isset($options['group'])) {
			$options['group'] = 'USERS';
		}

		// if(!$acl->is_group_child_of( $grp->name, $options['group'])) {
		// 	return JError::raiseWarning('SOME_ERROR_CODE', JText::_('E_NOLOGIN_ACCESS'));
		// }

		//Mark the user as logged in
		$instance->set( 'guest', 0);
		$instance->set('aid', 1);

		// Fudge Authors, Editors, Publishers and Super Administrators into the special access group
		// if ($acl->is_group_child_of($grp->name, 'Registered')      ||
		//     $acl->is_group_child_of($grp->name, 'Public Backend'))    {
		// 	$instance->set('aid', 2);
		// }

		//Set the usertype based on the ACL group name
		$instance->set('usertype', $grp->name);

		// Register the needed session variables
		$session =& JFactory::getSession();
		$session->set('user', $instance);

		// Get the session object
		$table = & JTable::getInstance('session');
		$table->load( $session->getId() );

		$table->guest 		= $instance->get('guest');
		$table->username 	= $instance->get('username');
		$table->userid 		= intval($instance->get('id'));
		$table->usertype 	= $instance->get('usertype');
		//$table->gid 		= intval($instance->get('gid'));

		$table->update();

		// Hit the user last visit field
		$instance->setLastVisit();
		$app->redirect('index.php', JText::_('SWITCHUSER_YOU_HAVE_LOGIN_SUCCESSFULLY'));
	}
}