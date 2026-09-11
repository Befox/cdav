<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
$cdavAdminTab = 'compatibility';
require __DIR__.'/_common.php';
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><th>'.$langs->trans('Parameter').'</th><th>'.$langs->trans('Value').'</th></tr>';
foreach (array('Dolibarr' => DOL_VERSION, 'PHP' => PHP_VERSION, 'CDavMinimum' => 'Dolibarr '.implode('.', $moduleDescriptor->need_dolibarr_version).' / PHP '.implode('.', $moduleDescriptor->phpmin), 'CDavTarget' => 'Dolibarr 16–24') as $key => $value) {
	print '<tr class="oddeven"><td>'.$langs->trans($key).'</td><td>'.dol_escape_htmltag($value).'</td></tr>';
}
print '</table></div><br>';
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><th>'.$langs->trans('CDavCapability').'</th><th>'.$langs->trans('Status').'</th><th>'.$langs->trans('Description').'</th><th>'.$langs->trans('CDavCoreAvailability').'</th></tr>';
foreach (CDavCompatibility::getFeatures() as $feature) {
	print '<tr class="oddeven"><td>'.$langs->trans($feature['label']).'</td><td>';
	print '<span class="badge badge-status'.($feature['available'] ? '4' : '0').'">'.$langs->trans($feature['available'] ? 'CDavAvailable' : 'CDavUnavailable').'</span>';
	print '</td><td>'.$langs->trans($feature['reason']).($feature['detail'] !== '' ? '<br>'.dol_escape_htmltag($feature['detail']) : '').'</td>';
	print '<td>Dolibarr '.dol_escape_htmltag($feature['core_versions']).'<br>'.$langs->trans('CDavMinimum').': '.dol_escape_htmltag($feature['min_dolibarr']).' / PHP '.dol_escape_htmltag($feature['min_php']).'</td></tr>';
}
print '</table></div><br>';
// Keep diagnostics embedded in the page, independently of the notification timeout.
print get_htmloutput_mesg($langs->trans('CDavVersionPolicy'), array(), 'info', 1);
print get_htmloutput_mesg($langs->trans('CDavManualChecks'), array(), 'warning', 1);
print get_htmloutput_mesg($langs->trans('CDavMulticompanyChecks'), array(), 'warning', 1);
print get_htmloutput_mesg($langs->trans('CDavTransverseMode').': '.$langs->trans(isModEnabled('multicompany') && getDolGlobalInt('MULTICOMPANY_TRANSVERSE_MODE') ? 'Yes' : 'No'), array(), 'info', 1);
print dol_get_fiche_end();
llxFooter();
$db->close();
