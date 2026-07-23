<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * English language strings for mod_selfselectadvanced.
 *
 * Keys are kept in alphabetical order (moodle-cs LangFilesOrdering).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['accept'] = 'Accept';
$string['allgroups'] = 'All groups';
$string['autogroup'] = 'Auto-group students left without a group';
$string['autogroup_help'] = 'If enabled, students confirmed in no group when the "Allowed till" deadline passes are grouped automatically by system logic, honouring the quota rules in their priority order. The run can also be triggered manually by a manager.';
$string['cancelnomination'] = 'Cancel nomination';
$string['counterlead'] = 'You lead {$a->current} of {$a->max} groups';
$string['countermember'] = 'You are a member of {$a->current} of {$a->max} groups';
$string['creategroup'] = 'Create group';
$string['decline'] = 'Decline';
$string['deletegroup'] = 'Delete group';
$string['deletegroupconfirm'] = 'Delete the group "{$a}"? Members are notified and pending invitations are cancelled. This cannot be undone.';
$string['errdatesorder'] = 'Dates must be in order: open, then penalty-free deadline, then allowed till.';
$string['errleadgtmembership'] = 'The maximum groups a student may lead cannot exceed the maximum groups they may belong to.';
$string['errlocktimeout'] = 'Another change to this group is in progress. Please try again.';
$string['errminsizegtmax'] = 'Minimum group size cannot exceed maximum group size.';
$string['errnametaken'] = 'That group name is already taken in this activity.';
$string['errnonnegative'] = 'This value cannot be negative.';
$string['errpositiveint'] = 'This value must be a whole number of at least 1.';
$string['errwrongstate'] = 'This action is not available while the group is in the "{$a}" state.';
$string['eventgroupcreated'] = 'Group created';
$string['eventgroupdeleted'] = 'Group deleted';
$string['eventinvitationaccepted'] = 'Invitation accepted';
$string['eventinvitationdeclined'] = 'Invitation declined';
$string['eventinvitationexpired'] = 'Invitation expired';
$string['eventinvitationsent'] = 'Invitation sent';
$string['eventinvitationwithdrawn'] = 'Invitation withdrawn';
$string['eventleadershiptransferred'] = 'Leadership transferred';
$string['formationwindow'] = 'Formation window';
$string['grade'] = 'Point value of the activity';
$string['grade_help'] = 'The gradebook item created for this activity carries this maximum. A student\'s grade is this value minus the late penalty of each group they are a confirmed member of.';
$string['groupcreated'] = 'Group created with ID {$a}.';
$string['groupdeleted'] = 'Group {$a} deleted.';
$string['groupname'] = 'Group name';
$string['groupname_help'] = 'A name for your group, unique within this activity. The name is fixed once chosen; the system also assigns a permanent group ID.';
$string['groupsizeheading'] = 'Group size';
$string['guidemode'] = 'Guide selection';
$string['guidemode_help'] = 'Either the group leader selects a guide when submitting the group, or groups are submitted without a guide and a manager assigns one.';
$string['guidemodeleader'] = 'Leader selects the guide';
$string['guidemodemanager'] = 'Manager assigns the guide';
$string['guidesheading'] = 'Guides';
$string['invitationaccepted'] = 'You have joined the group "{$a}".';
$string['invitationdeclined'] = 'You declined the invitation to the group "{$a}".';
$string['invitationpending'] = 'Invitation pending';
$string['invitationssent'] = '{$a} invitation(s) sent.';
$string['invitationwithdrawn'] = 'The invitation was withdrawn and its seat released.';
$string['inviteexpiry'] = 'Invitation expiry (days)';
$string['inviteexpiry_help'] = 'Pending invitations are automatically declined this many days after being sent, releasing their reserved seat. Zero means invitations never expire.';
$string['invitemembers'] = 'Invite members';
$string['invitenoselection'] = 'No one selected yet';
$string['inviteplaceholder'] = 'Search by name or email';
$string['landingnotready'] = 'Group formation is being set up for this activity. Please check back soon.';
$string['leader'] = 'Leader';
$string['maxguided'] = 'Maximum groups per guide';
$string['maxguided_help'] = 'The default maximum number of groups a guide may take on in this activity. Per-guide exceptions are granted through overrides.';
$string['maxlead'] = 'Maximum groups a student may lead';
$string['maxlead_help'] = 'How many groups one student may lead in this activity at the same time. Cannot exceed the maximum group memberships.';
$string['maxmembership'] = 'Maximum group memberships per student';
$string['maxmembership_help'] = 'How many groups one student may belong to in this activity, counting groups they lead and groups they have joined.';
$string['maxsize'] = 'Maximum group size';
$string['maxsize_help'] = 'The maximum number of members per group. Pending invitations reserve seats, so confirmed members plus pending invitations never exceed this number.';
$string['messageprovider:invitation'] = 'Group invitation received';
$string['messageprovider:invitationresult'] = 'Group invitation outcomes (accepted, declined, expired, withdrawn)';
$string['messageprovider:nomination'] = 'Succession nomination received';
$string['messageprovider:nominationresult'] = 'Succession nomination outcomes (confirmed, declined, cancelled)';
$string['minsize'] = 'Minimum group size';
$string['minsize_help'] = 'The minimum number of confirmed members, leader included, before a group can be submitted to a guide.';
$string['minsizenote'] = 'Minimum group size: {$a->min} confirmed members';
$string['modulename'] = 'Group self-selection (Advanced)';
$string['modulename_help'] = 'Students self-organise into groups under teacher-defined constraints: group size limits, membership caps, composition quotas and a formation window with late penalties. A guide reviews and approves each group; approved groups are frozen into Moodle course groups that any other activity can use.';
$string['modulenameplural'] = 'Group self-selections (Advanced)';
$string['msgacceptedbody'] = '{$a->user} accepted the invitation and is now a confirmed member of "{$a->group}".';
$string['msgacceptedsubject'] = '{$a->user} joined "{$a->group}"';
$string['msgcascadebody'] = '{$a->user} reached their group membership limit, so their pending invitation to "{$a->group}" was automatically declined and its seat released.';
$string['msgcascadesubject'] = 'Invitation to "{$a->group}" auto-declined';
$string['msgdeclinedbody'] = '{$a->user} declined the invitation to "{$a->group}". The reserved seat has been released.';
$string['msgdeclinedsubject'] = '{$a->user} declined the invitation to "{$a->group}"';
$string['msgexpiredinviteebody'] = 'Your invitation to the group "{$a->group}" expired before you responded.';
$string['msgexpiredinviteesubject'] = 'Invitation to "{$a->group}" expired';
$string['msgexpiredleaderbody'] = 'The invitation for {$a->user} to join "{$a->group}" expired and its seat has been released.';
$string['msgexpiredleadersubject'] = 'An invitation to "{$a->group}" expired';
$string['msginvitationbody'] = 'You have been invited to join the group "{$a->group}" ({$a->pluginuid}) in {$a->activity}. Open the link to see the group details and accept or decline.';
$string['msginvitationsubject'] = 'Invitation to join "{$a->group}"';
$string['msgnominationbodystepout'] = 'The leader of "{$a->group}" ({$a->pluginuid}) nominated you to take over as leader so they can step out of the group. Open the group page to accept or decline.';
$string['msgnominationbodytransfer'] = 'The leader of "{$a->group}" ({$a->pluginuid}) nominated you as the new leader. Open the group page to accept or decline.';
$string['msgnominationcancelledbody'] = 'The succession nomination in "{$a->group}" was cancelled by the leader.';
$string['msgnominationcancelledsubject'] = 'Nomination in "{$a->group}" cancelled';
$string['msgnominationconfirmedbody'] = '{$a->user} accepted your nomination and is now the leader of "{$a->group}".';
$string['msgnominationconfirmedsubject'] = 'Leadership of "{$a->group}" transferred';
$string['msgnominationdeclinedbody'] = 'Your succession nominee declined in "{$a->group}". You remain the leader.';
$string['msgnominationdeclinedsubject'] = 'Nomination in "{$a->group}" declined';
$string['msgnominationsubject'] = 'You have been nominated as leader of "{$a->group}"';
$string['msgwithdrawnbody'] = 'Your invitation to the group "{$a->group}" was withdrawn by the group leader.';
$string['msgwithdrawnsubject'] = 'Invitation to "{$a->group}" withdrawn';
$string['mygroups'] = 'My groups';
$string['myinvitations'] = 'My invitations';
$string['mynominations'] = 'My nominations';
$string['nogroupsyet'] = 'No groups yet.';
$string['noinvitations'] = 'No pending invitations.';
$string['nominate'] = 'Nominate';
$string['nominationbannerstepout'] = '{$a} has been nominated to take over as leader so the current leader can step out.';
$string['nominationbannertransfer'] = '{$a} has been nominated as the new leader.';
$string['nominationcancelled'] = 'The nomination was cancelled.';
$string['nominationdeclined'] = 'You declined the nomination.';
$string['nominationsent'] = 'Nomination sent. It takes effect when the nominee accepts.';
$string['nominationstepoutbadge'] = 'Step-out nomination';
$string['nominationtransferbadge'] = 'Leadership nomination';
$string['nominee'] = 'Successor';
$string['nomineeexcluded'] = '{$a->name} cannot be nominated: {$a->reason}';
$string['penaltyheading'] = 'Late penalty';
$string['penaltyperday'] = 'Penalty per day late';
$string['penaltyperday_help'] = 'Applied for each day a group is approved after the penalty-free deadline, up to the "Allowed till" hard stop. Groups formed within an overridden window incur no penalty.';
$string['penaltytype'] = 'Penalty type';
$string['penaltytype_help'] = 'Whether the daily penalty is a percentage of the activity point value or a fixed number of points.';
$string['penaltytypepercent'] = 'Percentage of the point value';
$string['penaltytypepoints'] = 'Points';
$string['pendinginvitations'] = 'Pending invitations';
$string['pluginadministration'] = 'Group self-selection (Advanced) administration';
$string['pluginid'] = 'Group ID';
$string['pluginname'] = 'Group self-selection (Advanced)';
$string['refusalalreadyinvited'] = 'This user already has a pending invitation to this group.';
$string['refusalalreadymember'] = 'This user is already a confirmed member of this group.';
$string['refusalcutoffpassed'] = 'The formation deadline ({$a}) has passed.';
$string['refusalinviteecap'] = 'This user is already a member of {$a->current} of {$a->max} groups.';
$string['refusalleadcap'] = 'You already lead {$a->current} of {$a->max} groups.';
$string['refusalmembershipcap'] = 'You are already a member of {$a->current} of {$a->max} groups.';
$string['refusalnominationactive'] = 'A succession nomination is already awaiting a response. Cancel it first.';
$string['refusalnomineeisleader'] = 'The leader cannot nominate themselves.';
$string['refusalnomineeleadcap'] = 'This member already leads {$a->current} of {$a->max} groups.';
$string['refusalnomineenotmember'] = 'Only a confirmed member of the group can be nominated.';
$string['refusalnoseats'] = 'No free seats — withdraw an invitation to invite someone else.';
$string['refusalnotinvited'] = 'There is no pending invitation to act on.';
$string['refusalnotleader'] = 'Only the group leader can do this.';
$string['refusalnotnominee'] = 'There is no nomination awaiting your response.';
$string['refusalnotopen'] = 'Group formation opens on {$a}.';
$string['refusalreplacementneeded'] = 'Stepping out would leave {$a->after} confirmed members, below the minimum of {$a->min}. A replacement member must be invited and confirmed first.';
$string['refusalwrongstate'] = 'This action is not available in the group\'s current state.';
$string['respondprompt'] = 'You are invited to join this group.';
$string['roster'] = 'Members';
$string['seatsummary'] = '{$a->confirmed} of {$a->max} seats filled, {$a->invited} invitation(s) pending';
$string['selfselectadvanced:addinstance'] = 'Add a new Group self-selection (Advanced) activity';
$string['selfselectadvanced:creategroup'] = 'Create groups and act as leader';
$string['selfselectadvanced:freeze'] = 'Freeze approved groups into course groups';
$string['selfselectadvanced:guide'] = 'Act as a project guide: review, return and approve groups';
$string['selfselectadvanced:ingestattributes'] = 'Ingest and edit participant attributes (site administrators)';
$string['selfselectadvanced:manage'] = 'Configure rules, quotas and dates; stage moves; run auto-grouping';
$string['selfselectadvanced:override'] = 'Create, edit and delete overrides';
$string['selfselectadvanced:respond'] = 'Accept or decline invitations and nominations';
$string['selfselectadvanced:unfreeze'] = 'Unfreeze frozen groups';
$string['selfselectadvanced:viewall'] = 'See all groups, members, states and the penalty ledger';
$string['sendinvitations'] = 'Send invitations';
$string['size'] = 'Size';
$string['state'] = 'State';
$string['statefirm'] = 'Firm';
$string['stateforming'] = 'Forming';
$string['statefrozen'] = 'Frozen';
$string['statependingguide'] = 'Awaiting guide';
$string['studentlimitsheading'] = 'Student limits';
$string['succession'] = 'Leadership succession';
$string['successionstepout'] = 'Transfer leadership and step out of the group';
$string['successionstepoutdone'] = 'You are now the leader; the former leader has left the group.';
$string['successiontransfer'] = 'Transfer leadership (the current leader stays as a member)';
$string['successiontransferdone'] = 'You are now the leader of this group.';
$string['successiontype'] = 'Succession type';
$string['successiontype_help'] = 'Transfer makes the nominee leader while the current leader remains a confirmed member. Step out additionally removes the current leader from the group; if that would drop the group below its minimum size, a replacement member must be invited and confirmed before the nominee can accept.';
$string['taskexpireinvitations'] = 'Expire pending group invitations';
$string['timecutoff'] = 'Allowed till';
$string['timecutoff_help'] = 'The hard stop. After this time no group formation actions are possible and auto-grouping runs, if enabled.';
$string['timedue'] = 'Penalty-free deadline';
$string['timedue_help'] = 'Groups approved after this time accrue the late penalty per day, up to the "Allowed till" hard stop.';
$string['timeopen'] = 'Open from';
$string['timeopen_help'] = 'Group formation opens at this time. Before it, students cannot create groups or respond to invitations.';
$string['withdraw'] = 'Withdraw';
$string['workbrief'] = 'Brief of work';
$string['worktitle'] = 'Title of work';
