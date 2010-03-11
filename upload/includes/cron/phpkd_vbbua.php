<?php
// ######################## SET PHP ENVIRONMENT ###########################
error_reporting(E_ALL & ~E_NOTICE & ~8192);
if (!is_object($vbulletin->db))
{
	exit;
}


// ########################################################################
// ######################### START MAIN SCRIPT ############################
// ########################################################################

require_once(DIR . '/includes/functions_misc.php');

if (!defined('IN_CONTROL_PANEL'))
{
	global $vbphrase;
}


// ########################################################################
if ($vbulletin->options['phpkd_vbbua_active'])
{
	if ($vbulletin->options['phpkd_vbbua_staff'])
	{
		$userinfo = fetch_userinfo($vbulletin->options['phpkd_vbbua_staff']);
		cache_permissions($userinfo);
		$canbanuser = ($userinfo['permissions']['adminpermissions'] & $vbulletin->bf_ugp_adminpermissions['cancontrolpanel'] OR can_moderate(0, 'canbanusers', $userinfo['userid'])) ? true : false;
	}
	else
	{
		if (defined('IN_CONTROL_PANEL'))
		{
			echo $vbphrase['phpkd_vbbua_cron_error'];
		}

		die();
	}

	$whereclause = "";
	if ($vbulletin->options['phpkd_vbbua_excluded_guids'] != '')
	{
		$excluded_guids = explode(' ', trim($vbulletin->options['phpkd_vbbua_excluded_guids']));
		if (count($excluded_guids) > 0)
		{
			$whereclause = "AND usergroupid NOT IN (" . implode(',', $excluded_guids) . ")";
		}
	}

	$users = $vbulletin->db->query_read("
		SELECT userid, username, email, birthday, languageid
		FROM " . TABLE_PREFIX . "user
		WHERE birthday != ''
			$whereclause
			LIMIT " . intval(trim($vbulletin->options['phpkd_vbbua_limit']))
	);

	$banuids = array();
	while ($user = $vbulletin->db->fetch_array($users))
	{
		if ($user['birthday'])
		{
			$bday[$user['userid']] = explode('-', $user['birthday']);

			$year[$user['userid']] = vbdate('Y', TIMENOW, false, false);
			$month[$user['userid']] = vbdate('m', TIMENOW, false, false);
			$day[$user['userid']] = vbdate('d', TIMENOW, false, false);
			if ($year[$user['userid']] >= $bday[$user['userid']][2] AND $bday[$user['userid']][2] != '0000')
			{
				$age[$user['userid']]['age'] = $year[$user['userid']] - $bday[$user['userid']][2];
				$age[$user['userid']]['birthday'] = explode("-", $user['birthday']);
				$age[$user['userid']]['remaining'] = abs(round((($vbulletin->options['phpkd_vbbua_age'] * 31556926) - (TIMENOW - vbmktime(0, 0, 0, $age[$user['userid']]['birthday'][0], $age[$user['userid']]['birthday'][1], $age[$user['userid']]['birthday'][2]))) / 86400));
				if ($month[$user['userid']] < $bday[$user['userid']][0] OR ($month[$user['userid']] == $bday[$user['userid']][0] AND $day[$user['userid']] < $bday[$user['userid']][1]))
				{
					$age[$user['userid']]['age']--;
				}
			}
		}

		if ($age[$user['userid']]['age'] < $vbulletin->options['phpkd_vbbua_age'] AND $age[$user['userid']]['remaining'])
		{
			$age[$user['userid']]['remaining'] = "D_" . $age[$user['userid']]['remaining'];
			$banuids[$user['userid']] = $age[$user['userid']];
		}
	}


	$banned = array();

	// Begin banning process!
	if ($canbanuser)
	{
		// check that the target usergroup is valid
		if (isset($vbulletin->usergroupcache["{$vbulletin->options['phpkd_vbbua_bugid']}"]) OR ($vbulletin->usergroupcache["{$vbulletin->options['phpkd_vbbua_bugid']}"]['genericoptions'] & $vbulletin->bf_ugp_genericoptions['isnotbannedgroup']))
		{
			if ($vbulletin->options['phpkd_vbbua_email'])
			{
				vbmail_start();
			}

			foreach ($banuids AS $banuid => $banu)
			{
				// check that the user exists
				$user = $vbulletin->db->query_first("
					SELECT user.*,
						IF(moderator.moderatorid IS NULL, 0, 1) AS ismoderator
					FROM " . TABLE_PREFIX . "user AS user
					LEFT JOIN " . TABLE_PREFIX . "moderator AS moderator ON(moderator.userid = user.userid AND moderator.forumid <> -1)
					WHERE user.userid = '$banuid'
				");

				if ($user AND $user['userid'] != $vbulletin->options['phpkd_vbbua_staff'] AND !phpkd_vbbua_is_unalterable_user($user['userid']))
				{
					cache_permissions($user);

					// Check for appropriate administrative permissions
					if ($userinfo['permissions']['adminpermissions'] & $vbulletin->bf_ugp_adminpermissions['cancontrolpanel'])
					{
						// You can't auto ban any administrator/super moderator/moderator!
						if (!($user['permissions']['adminpermissions'] & $vbulletin->bf_ugp_adminpermissions['cancontrolpanel']) AND !($user['permissions']['adminpermissions'] & $vbulletin->bf_ugp_adminpermissions['ismoderator']) AND !($user['ismoderator']))
						{
							// check that the number of days is valid
							if (preg_match('#^(D|M|Y)_[1-9][0-9]?[0-9]?[0-9]?$#', $banu['remaining']))
							{
								require_once(DIR . '/includes/functions_banning.php');

								// get the unixtime for when this ban will be lifted
								$liftdate = convert_date_to_timestamp($banu['remaining']);

								// check to see if there is already a ban record for this user in the userban table
								if ($check = $vbulletin->db->query_first("SELECT userid, liftdate FROM " . TABLE_PREFIX . "userban WHERE userid = $user[userid]"))
								{
									if ($liftdate AND $liftdate < $check['liftdate'])
									{
										if (!($userinfo['permissions']['adminpermissions'] & $vbulletin->bf_ugp_adminpermissions['cancontrolpanel']) AND !can_moderate(0, 'canunbanusers', $userinfo['userid']))
										{
											continue;
										}
									}

									// there is already a record - just update this record
									$vbulletin->db->query_write("
										UPDATE " . TABLE_PREFIX . "userban SET
											bandate = " . TIMENOW . ",
											liftdate = $liftdate,
											adminid = " . $userinfo['userid'] . ",
											reason = '" . $vbulletin->db->escape_string(construct_phrase($vbphrase['phpkd_vbbua_reason'], $vbulletin->options['phpkd_vbbua_age'])) . "'
										WHERE userid = $user[userid]
									");
								}
								else
								{
									// insert a record into the userban table
									/*insert query*/
									$vbulletin->db->query_write("
										INSERT INTO " . TABLE_PREFIX . "userban
											(userid, usergroupid, displaygroupid, customtitle, usertitle, adminid, bandate, liftdate, reason)
										VALUES
											($user[userid], $user[usergroupid], $user[displaygroupid], $user[customtitle], '" . $vbulletin->db->escape_string($user['usertitle']) . "', " . $userinfo['userid'] . ", " . TIMENOW . ", $liftdate, '" . $vbulletin->db->escape_string(construct_phrase($vbphrase['phpkd_vbbua_reason'], $vbulletin->options['phpkd_vbbua_age'])) . "')
									");


									if (defined('IN_CONTROL_PANEL'))
									{
										$banned["$user[userid]"] = $user['username'];
									}
								}

								// update the user record
								$userdm =& datamanager_init('User', $vbulletin, ERRTYPE_SILENT);
								$userdm->set_existing($user);
								$userdm->set('usergroupid', $vbulletin->options['phpkd_vbbua_bugid']);
								$userdm->set('displaygroupid', 0);

								// update the user's title if they've specified a special user title for the banned group
								if ($vbulletin->usergroupcache["{$vbulletin->options['phpkd_vbbua_bugid']}"]['usertitle'] != '')
								{
									$userdm->set('usertitle', $vbulletin->usergroupcache["{$vbulletin->options['phpkd_vbbua_bugid']}"]['usertitle']);
									$userdm->set('customtitle', 0);
								}

								$userdm->save();
								unset($userdm);


								// Email banned underage user
								if ($vbulletin->options['phpkd_vbbua_email'])
								{
									$username = unhtmlspecialchars($user['username']);
									$userage = $vbulletin->options['phpkd_vbbua_age'];
									eval(fetch_email_phrases('phpkd_vbbua', $user['languageid']));
									vbmail($user['email'], $subject, $message);
								}
							}
						}
					}
				}
			}

			if ($vbulletin->options['phpkd_vbbua_email'])
			{
				vbmail_end();
			}
		}

		if (defined('IN_CONTROL_PANEL') AND count($banned) > 0)
		{
			echo "<ol>";
			foreach ($banned AS $banneduid => $banneduname)
			{
				echo "<li><a href=\"user.php?" . $vbulletin->session->vars['sessionurl'] . "do=edit&amp;u=$banneduid\" target=\"_blank\">$banneduname</a></li>";
			}
			echo "</ol><br /><a href=\"../" . $vbulletin->config['Misc']['modcpdir'] . "/banning.php?do=modify\">" . $vbphrase['phpkd_vbbua_view_banned_users'] . "</a>";
			$banned["$user[userid]"] = $user['username'];
		}
	}
}

log_cron_action('', $nextitem, 1);


/**
* Checks userid is a user that shouldn't be editable
*
* @param	integer	userid to check
*
* @return	boolean
*/
function phpkd_vbbua_is_unalterable_user($userid)
{
	global $vbulletin;

	static $noalter = null;

	if (!$userid)
	{
		return false;
	}

	if ($noalter === null)
	{
		$noalter = explode(',', $vbulletin->config['SpecialUsers']['undeletableusers']);

		if (!is_array($noalter))
		{
			$noalter = array();
		}
	}

	return in_array($userid, $noalter);
}
?>