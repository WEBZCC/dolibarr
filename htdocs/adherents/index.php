<?php
/* Copyright (C) 2001-2002	Rodolphe Quiedeville	<rodolphe@quiedeville.org>
 * Copyright (C) 2003		Jean-Louis Bergamo	<jlb@j1b.org>
 * Copyright (C) 2004-2020	Laurent Destailleur	<eldy@users.sourceforge.net>
 * Copyright (C) 2005-2012	Regis Houssin		<regis.houssin@inodbox.com>
 * Copyright (C) 2019       Nicolas ZABOURI         <info@inovea-conseil.com>
 * Copyright (C) 2021		Frédéric France			<frederic.france@netlgic.fr>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 *       \file       htdocs/adherents/index.php
 *       \ingroup    member
 *       \brief      Home page of membership module
 */

require '../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';
require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent_type.class.php';
require_once DOL_DOCUMENT_ROOT.'/adherents/class/subscription.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formother.class.php';

$hookmanager = new HookManager($db);

// Initialize technical object to manage hooks. Note that conf->hooks_modules contains array
$hookmanager->initHooks(array('membersindex'));

// Load translation files required by the page
$langs->loadLangs(array("companies", "members"));

// Security check
$result = restrictedArea($user, 'adherent');


/*
 * Actions
 */

if (GETPOST('addbox')) {
	// Add box (when submit is done from a form when ajax disabled)
	require_once DOL_DOCUMENT_ROOT.'/core/class/infobox.class.php';
	$zone = GETPOST('areacode', 'aZ09');
	$userid = GETPOST('userid', 'int');
	$boxorder = GETPOST('boxorder', 'aZ09');
	$boxorder .= GETPOST('boxcombo', 'aZ09');
	$result = InfoBox::saveboxorder($db, $zone, $boxorder, $userid);
	if ($result > 0) {
		setEventMessages($langs->trans("BoxAdded"), null);
	}
}


/*
 * View
 */

$form = new Form($db);

// Load $resultboxes (selectboxlist + boxactivated + boxlista + boxlistb)
$resultboxes = FormOther::getBoxesArea($user, "2");

llxHeader('', $langs->trans("Members"), 'EN:Module_Foundations|FR:Module_Adh&eacute;rents|ES:M&oacute;dulo_Miembros');

$staticmember = new Adherent($db);
$statictype = new AdherentType($db);
$subscriptionstatic = new Subscription($db);

print load_fiche_titre($langs->trans("MembersArea"), $resultboxes['selectboxlist'], 'members');

$MembersValidated = array();
$MembersToValidate = array();
$MembersUpToDate = array();
$MembersResiliated = array();

$AdherentType = array();

// Type of membership
$sql = "SELECT t.rowid, t.libelle as label, t.subscription,";
$sql .= " d.statut, count(d.rowid) as somme";
$sql .= " FROM ".MAIN_DB_PREFIX."adherent_type as t";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."adherent as d";
$sql .= " ON t.rowid = d.fk_adherent_type";
$sql .= " AND d.entity IN (".getEntity('adherent').")";
$sql .= " WHERE t.entity IN (".getEntity('member_type').")";
$sql .= " GROUP BY t.rowid, t.libelle, t.subscription, d.statut";

dol_syslog("index.php::select nb of members per type", LOG_DEBUG);
$result = $db->query($sql);
if ($result) {
	$num = $db->num_rows($result);
	$i = 0;
	while ($i < $num) {
		$objp = $db->fetch_object($result);

		$adhtype = new AdherentType($db);
		$adhtype->id = $objp->rowid;
		$adhtype->subscription = $objp->subscription;
		$adhtype->label = $objp->label;
		$AdherentType[$objp->rowid] = $adhtype;

		if ($objp->statut == -1) {
			$MembersToValidate[$objp->rowid] = $objp->somme;
		}
		if ($objp->statut == 1) {
			$MembersValidated[$objp->rowid] = $objp->somme;
		}
		if ($objp->statut == 0) {
			$MembersResiliated[$objp->rowid] = $objp->somme;
		}

		$i++;
	}
	$db->free($result);
}

$now = dol_now();

// Members up to date list
// current rule: uptodate = the end date is in future whatever is type
// old rule: uptodate = if type does not need payment, that end date is null, if type need payment that end date is in future)
$sql = "SELECT count(*) as somme , d.fk_adherent_type";
$sql .= " FROM ".MAIN_DB_PREFIX."adherent as d, ".MAIN_DB_PREFIX."adherent_type as t";
$sql .= " WHERE d.entity IN (".getEntity('adherent').")";
$sql .= " AND d.statut = 1 AND (d.datefin >= '".$db->idate($now)."' OR t.subscription = 0)";
$sql .= " AND t.rowid = d.fk_adherent_type";
$sql .= " GROUP BY d.fk_adherent_type";

dol_syslog("index.php::select nb of uptodate members by type", LOG_DEBUG);
$result = $db->query($sql);
if ($result) {
	$num = $db->num_rows($result);
	$i = 0;
	while ($i < $num) {
		$objp = $db->fetch_object($result);
		$MembersUpToDate[$objp->fk_adherent_type] = $objp->somme;
		$i++;
	}
	$db->free();
}

$searchbox = '';
if (!empty($conf->global->MAIN_SEARCH_FORM_ON_HOME_AREAS)) {     // This is useless due to the global search combo
	// Search contact/address
	if (!empty($conf->adherent->enabled) && $user->rights->adherent->lire) {
		$listofsearchfields['search_member'] = array('text'=>'Member');
	}

	if (count($listofsearchfields)) {
		$searchbox .='<form method="post" action="'.DOL_URL_ROOT.'/core/search.php">';
		$searchbox .='<input type="hidden" name="token" value="'.newToken().'">';
		$searchbox .='<div class="div-table-responsive-no-min">';
		$searchbox .='<table class="noborder nohover centpercent">';
		$i = 0;
		foreach ($listofsearchfields as $key => $value) {
			if ($i == 0) {
				$searchbox .='<tr class="liste_titre"><td colspan="3">'.$langs->trans("Search").'</td></tr>';
			}
			$searchbox .='<tr class="oddeven">';
			$searchbox .='<td class="nowrap"><label for="'.$key.'">'.$langs->trans($value["text"]).'</label>:</td><td><input type="text" class="flat inputsearch" name="'.$key.'" id="'.$key.'" size="18"></td>';
			if ($i == 0) {
				$searchbox .='<td rowspan="'.count($listofsearchfields).'"><input type="submit" value="'.$langs->trans("Search").'" class="button"></td>';
			}
			$searchbox .='</tr>';
			$i++;
		}
		$searchbox .='</table>';
		$searchbox .='</div>';
		$searchbox .='</form>';
		$searchbox .='<br>';
	}
}


/*
 * Statistics
 */
$boxgraph = '';
if ($conf->use_javascript_ajax) {
	$boxgraph .='<div class="div-table-responsive-no-min">';
	$boxgraph .='<table class="noborder nohover centpercent">';
	$boxgraph .='<tr class="liste_titre"><th colspan="2">'.$langs->trans("Statistics").'</th></tr>';
	$boxgraph .='<tr><td class="center" colspan="2">';

	$SommeA = 0;
	$SommeB = 0;

	$SommeC = 0;
	$SommeD = 0;
	$total = 0;
	$dataval = array();
	$i = 0;
	foreach ($AdherentType as $key => $adhtype) {
		$dataval['draft'][] = array($i, isset($MembersToValidate[$key]) ? $MembersToValidate[$key] : 0);
		$dataval['notuptodate'][] = array($i, isset($MembersValidated[$key]) ? $MembersValidated[$key] - (isset($MembersUpToDate[$key]) ? $MembersUpToDate[$key] : 0) : 0);
		$dataval['uptodate'][] = array($i, isset($MembersUpToDate[$key]) ? $MembersUpToDate[$key] : 0);
		$dataval['resiliated'][] = array($i, isset($MembersResiliated[$key]) ? $MembersResiliated[$key] : 0);
		$SommeA += isset($MembersToValidate[$key]) ? $MembersToValidate[$key] : 0;
		$SommeB += isset($MembersValidated[$key]) ? $MembersValidated[$key] - (isset($MembersUpToDate[$key]) ? $MembersUpToDate[$key] : 0) : 0;
		$SommeC += isset($MembersUpToDate[$key]) ? $MembersUpToDate[$key] : 0;
		$SommeD += isset($MembersResiliated[$key]) ? $MembersResiliated[$key] : 0;
		$i++;
	}
	$total = $SommeA + $SommeB + $SommeC + $SommeD;
	$dataseries = array();
	$dataseries[] = array($langs->transnoentitiesnoconv("OutOfDate"), round($SommeB));
	$dataseries[] = array($langs->transnoentitiesnoconv("UpToDate"), round($SommeC));
	$dataseries[] = array($langs->transnoentitiesnoconv("MembersStatusResiliated"), round($SommeD));
	$dataseries[] = array($langs->transnoentitiesnoconv("MembersStatusToValid"), round($SommeA));

	include DOL_DOCUMENT_ROOT.'/theme/'.$conf->theme.'/theme_vars.inc.php';

	include_once DOL_DOCUMENT_ROOT.'/core/class/dolgraph.class.php';
	$dolgraph = new DolGraph();
	$dolgraph->SetData($dataseries);
	$dolgraph->SetDataColor(array($badgeStatus1, $badgeStatus4, $badgeStatus6, '-'.$badgeStatus0));
	$dolgraph->setShowLegend(2);
	$dolgraph->setShowPercent(1);
	$dolgraph->SetType(array('pie'));
	$dolgraph->setHeight('200');
	$dolgraph->draw('idgraphstatus');
	$boxgraph .=$dolgraph->show($total ? 0 : 1);

	$boxgraph .= '</td></tr>';
	$boxgraph .= '<tr class="liste_total"><td>'.$langs->trans("Total").'</td><td class="right">';
	$boxgraph .= $SommeA + $SommeB + $SommeC + $SommeD;
	$boxgraph .= '</td></tr>';
	$boxgraph .= '</table>';
	$boxgraph .= '</div>';
	$boxgraph .= '<br>';
}

// boxes
print '<div class="clearboth"></div>';
print '<div class="fichecenter fichecenterbis">';

print '<div class="twocolumns">';

print '<div class="firstcolumn fichehalfleft boxhalfleft" id="boxhalfleft">';
print $searchbox;
print $boxgraph;

// List of subscription by year
$Total = array();
$Number = array();
$tot = 0;
$numb = 0;

$sql = "SELECT c.subscription, c.dateadh as dateh";
$sql .= " FROM ".MAIN_DB_PREFIX."adherent as d, ".MAIN_DB_PREFIX."subscription as c";
$sql .= " WHERE d.entity IN (".getEntity('adherent').")";
$sql .= " AND d.rowid = c.fk_adherent";


$result = $db->query($sql);
if ($result) {
	$num = $db->num_rows($result);
	$i = 0;
	while ($i < $num) {
		$objp = $db->fetch_object($result);
		$year = dol_print_date($db->jdate($objp->dateh), "%Y");
		$Total[$year] = (isset($Total[$year]) ? $Total[$year] : 0) + $objp->subscription;
		$Number[$year] = (isset($Number[$year]) ? $Number[$year] : 0) + 1;
		$tot += $objp->subscription;
		$numb += 1;
		$i++;
	}
}

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th>'.$langs->trans("Year").'</th>';
print '<th class="right">'.$langs->trans("Subscriptions").'</th>';
print '<th class="right">'.$langs->trans("AmountTotal").'</th>';
print '<th class="right">'.$langs->trans("AmountAverage").'</th>';
print "</tr>\n";

krsort($Total);
$i = 0;
foreach ($Total as $key=>$value) {
	if ($i >= 8) {
		print '<tr class="oddeven">';
		print "<td>...</td>";
		print "<td class=\"right\"></td>";
		print "<td class=\"right\"></td>";
		print "<td class=\"right\"></td>";
		print "</tr>\n";
		break;
	}
	print '<tr class="oddeven">';
	print "<td><a href=\"./subscription/list.php?date_select=$key\">$key</a></td>";
	print "<td class=\"right\">".$Number[$key]."</td>";
	print "<td class=\"right\">".price($value)."</td>";
	print "<td class=\"right\">".price(price2num($value / $Number[$key], 'MT'))."</td>";
	print "</tr>\n";
	$i++;
}

// Total
print '<tr class="liste_total">';
print '<td>'.$langs->trans("Total").'</td>';
print "<td class=\"right\">".$numb."</td>";
print '<td class="right">'.price($tot)."</td>";
print "<td class=\"right\">".price(price2num($numb > 0 ? ($tot / $numb) : 0, 'MT'))."</td>";
print "</tr>\n";
print "</table></div>";

print "<br>\n";

print $resultboxes['boxlista'];

print '</div>'."\n";

print '<div class="secondcolumn fichehalfright boxhalfright" id="boxhalfright">';

/*
 * Latest modified members
 */
$max = $conf->global->MAIN_SIZE_SHORTLIST_LIMIT;

$sql = "SELECT a.rowid, a.statut as status, a.lastname, a.firstname, a.societe as company, a.fk_soc,";
$sql .= " a.gender, a.email, a.photo, a.morphy,";
$sql .= " a.tms as datem, a.datefin as date_end_subscription,";
$sql .= " ta.rowid as typeid, ta.libelle as label, ta.subscription as need_subscription";
$sql .= " FROM ".MAIN_DB_PREFIX."adherent as a, ".MAIN_DB_PREFIX."adherent_type as ta";
$sql .= " WHERE a.entity IN (".getEntity('adherent').")";
$sql .= " AND a.fk_adherent_type = ta.rowid";
$sql .= $db->order("a.tms", "DESC");
$sql .= $db->plimit($max, 0);

$resql = $db->query($sql);
if ($resql) {
	print '<div class="div-table-responsive-no-min">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<th colspan="4">'.$langs->trans("LastMembersModified", $max).'</th></tr>';

	$num = $db->num_rows($resql);
	if ($num) {
		$i = 0;
		while ($i < $num) {
			$obj = $db->fetch_object($resql);

			$staticmember->id = $obj->rowid;
			$staticmember->ref = $obj->rowid;
			$staticmember->lastname = $obj->lastname;
			$staticmember->firstname = $obj->firstname;
			$staticmember->gender = $obj->gender;
			$staticmember->email = $obj->email;
			$staticmember->photo = $obj->photo;
			$staticmember->morphy = $obj->morphy;
			$staticmember->statut = $obj->status;
			$staticmember->need_subscription = $obj->need_subscription;
			$staticmember->datefin = $db->jdate($obj->date_end_subscription);
			if (!empty($obj->fk_soc)) {
				$staticmember->fk_soc = $obj->fk_soc;
				$staticmember->fetch_thirdparty();
				$staticmember->name = $staticmember->thirdparty->name;
			} else {
				$staticmember->name = $obj->company;
			}

			$statictype->id = $obj->typeid;
			$statictype->label = $obj->label;
			$statictype->subscription = $obj->need_subscription;

			print '<tr class="oddeven">';
			print '<td class="nowraponall">'.$staticmember->getNomUrl(-1, 32).'</td>';
			print '<td>'.$statictype->getNomUrl(1, 32).'</td>';
			print '<td>'.dol_print_date($db->jdate($obj->datem), 'dayhour').'</td>';
			print '<td class="right">'.$staticmember->getLibStatut(3).'</td>';
			print '</tr>';
			$i++;
		}
	}
	print "</table></div>";
	print "<br>";
} else {
	dol_print_error($db);
}


/*
 * Last modified subscriptions
 */
$max = $conf->global->MAIN_SIZE_SHORTLIST_LIMIT;

$sql = "SELECT a.rowid, a.statut as status, a.lastname, a.firstname, a.societe as company, a.fk_soc,";
$sql .= " a.gender, a.email, a.photo, a.morphy,";
$sql .= " a.datefin as date_end_subscription,";
$sql .= " ta.rowid as typeid, ta.libelle as label, ta.subscription as need_subscription,";
$sql .= " c.rowid as cid, c.tms as datem, c.datec as datec, c.dateadh as date_start, c.datef as date_end, c.subscription";
$sql .= " FROM ".MAIN_DB_PREFIX."adherent as a, ".MAIN_DB_PREFIX."adherent_type as ta, ".MAIN_DB_PREFIX."subscription as c";
$sql .= " WHERE a.entity IN (".getEntity('adherent').")";
$sql .= " AND a.fk_adherent_type = ta.rowid";
$sql .= " AND c.fk_adherent = a.rowid";
$sql .= $db->order("c.tms", "DESC");
$sql .= $db->plimit($max, 0);

$resql = $db->query($sql);
if ($resql) {
	print '<div class="div-table-responsive-no-min">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<th colspan="5">'.$langs->trans("LastSubscriptionsModified", $max).'</th></tr>';

	$num = $db->num_rows($resql);
	if ($num) {
		$i = 0;
		while ($i < $num) {
			$obj = $db->fetch_object($resql);

			$staticmember->id = $obj->rowid;
			$staticmember->ref = $obj->rowid;
			$staticmember->lastname = $obj->lastname;
			$staticmember->firstname = $obj->firstname;
			$staticmember->gender = $obj->gender;
			$staticmember->email = $obj->email;
			$staticmember->photo = $obj->photo;
			$staticmember->morphy = $obj->morphy;
			$staticmember->statut = $obj->status;
			$staticmember->need_subscription = $obj->need_subscription;
			$staticmember->datefin = $db->jdate($obj->date_end_subscription);
			if (!empty($obj->fk_soc)) {
				$staticmember->fk_soc = $obj->fk_soc;
				$staticmember->fetch_thirdparty();
				$staticmember->name = $staticmember->thirdparty->name;
			} else {
				$staticmember->name = $obj->company;
			}

			$subscriptionstatic->id = $obj->cid;
			$subscriptionstatic->ref = $obj->cid;

			print '<tr class="oddeven">';
			print '<td class="nowraponall">'.$subscriptionstatic->getNomUrl(1).'</td>';
			print '<td class="nowraponall">'.$staticmember->getNomUrl(-1, 32, 'subscription').'</td>';
			print '<td class="nowraponall">'.get_date_range($db->jdate($obj->date_start), $db->jdate($obj->date_end)).'</td>';
			print '<td class="right">'.price($obj->subscription).'</td>';
			//print '<td class="right">'.$staticmember->LibStatut($obj->statut,($obj->subscription=='yes'?1:0),$db->jdate($obj->date_end_subscription),5).'</td>';
			print '<td class="right nowraponall">'.dol_print_date($db->jdate($obj->datem ? $obj->datem : $obj->datec), 'dayhour').'</td>';
			print '</tr>';
			$i++;
		}
	}
	print "</table></div>";
	print "<br>";
} else {
	dol_print_error($db);
}


// Summary of members by type
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th>'.$langs->trans("MembersTypes").'</th>';
print '<th class=right>'.$langs->trans("MembersStatusToValid").'</th>';
print '<th class=right>'.$langs->trans("OutOfDate").'</th>';
print '<th class=right>'.$langs->trans("UpToDate").'</th>';
print '<th class=right>'.$langs->trans("MembersStatusResiliated").'</th>';
print "</tr>\n";

foreach ($AdherentType as $key => $adhtype) {
	print '<tr class="oddeven">';
	print '<td>'.$adhtype->getNomUrl(1, dol_size(32)).'</td>';
	print '<td class="right">'.(isset($MembersToValidate[$key]) && $MembersToValidate[$key] > 0 ? $MembersToValidate[$key] : '').' '.$staticmember->LibStatut(-1, $adhtype->subscription, 0, 3).'</td>';
	print '<td class="right">'.(isset($MembersValidated[$key]) && ($MembersValidated[$key] - (isset($MembersUpToDate[$key]) ? $MembersUpToDate[$key] : 0) > 0) ? $MembersValidated[$key] - (isset($MembersUpToDate[$key]) ? $MembersUpToDate[$key] : 0) : '').' '.$staticmember->LibStatut(1, $adhtype->subscription, 0, 3).'</td>';
	print '<td class="right">'.(isset($MembersUpToDate[$key]) && $MembersUpToDate[$key] > 0 ? $MembersUpToDate[$key] : '').' '.$staticmember->LibStatut(1, $adhtype->subscription, $now, 3).'</td>';
	print '<td class="right">'.(isset($MembersResiliated[$key]) && $MembersResiliated[$key] > 0 ? $MembersResiliated[$key] : '').' '.$staticmember->LibStatut(0, $adhtype->subscription, 0, 3).'</td>';
	print "</tr>\n";
}
print '<tr class="liste_total">';
print '<td class="liste_total">'.$langs->trans("Total").'</td>';
print '<td class="liste_total right">'.$SommeA.' '.$staticmember->LibStatut(-1, $adhtype->subscription, 0, 3).'</td>';
print '<td class="liste_total right">'.$SommeB.' '.$staticmember->LibStatut(1, $adhtype->subscription, 0, 3).'</td>';
print '<td class="liste_total right">'.$SommeC.' '.$staticmember->LibStatut(1, $adhtype->subscription, $now, 3).'</td>';
print '<td class="liste_total right">'.$SommeD.' '.$staticmember->LibStatut(0, $adhtype->subscription, 0, 3).'</td>';
print '</tr>';

print "</table>\n";
print "</div>";

print '<br>';

print $resultboxes['boxlistb'];

print '</div>'."\n";

print '</div>';
print '</div>';

$parameters = array('user' => $user);
$reshook = $hookmanager->executeHooks('dashboardMembers', $parameters, $object); // Note that $action and $object may have been modified by hook

// End of page
llxFooter();
$db->close();
