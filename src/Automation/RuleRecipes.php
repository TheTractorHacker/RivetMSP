<?php

declare(strict_types=1);

namespace RivetMSP\Automation;

use RivetCore\Webhooks\EventCatalog;

/**
 * Ready-made starting points for the rule editor. A recipe only prefills the form: nothing is saved until the person presses Save.
 * Every recipe uses an event this edition really emits (not one the catalog merely reserves) and an action that exists here; the
 * unit test enforces both so a recipe can never promise something the engine cannot do.
 */
final class RuleRecipes
{
    /** @return array<string,array{title:string,blurb:string,icon:string,name:string,trigger_event:string,conditions:array<string,string>,action_type:string,config:array<string,mixed>}> */
    public static function all(): array
    {
        $r = [
            'critical-ticket' => ['Tell me about Critical tickets', 'Notify the team the moment a Critical ticket is opened.', 'fa-exclamation-triangle', 'Notify on Critical tickets', 'ticket.created', ['ticket_priority' => 'Critical'], 'notify_user', ['message' => 'Critical ticket {ticket_number}: {ticket_subject} ({client_name})']],
            'ticket-webhook' => ['Send new tickets to another tool', 'POST every new ticket to a chat room or automation service.', 'fa-paper-plane', 'New tickets to webhook', 'ticket.created', [], 'send_webhook', ['url' => 'https://example.com/hooks/new-ticket', 'secret' => '']],
            'resolved-webhook' => ['Report resolved tickets', 'POST to a webhook whenever a ticket is resolved.', 'fa-check-circle', 'Resolved tickets to webhook', 'ticket.resolved', [], 'send_webhook', ['url' => 'https://example.com/hooks/resolved', 'secret' => '']],
            'credential-revealed' => ['Watch credential reveals', 'Tell the team whenever a stored password is revealed.', 'fa-key', 'Alert when a credential is revealed', 'vault.credential_revealed', [], 'notify_user', ['message' => '{summary}']],
            'signin-blocked' => ['Alert on blocked sign-ins', 'A sign-in is blocked after repeated failures or by an access rule.', 'fa-user-lock', 'Alert on blocked sign-ins', 'auth.login_blocked', [], 'notify_user', ['message' => 'Sign-in blocked: {summary}']],
            'failed-signin-ticket' => ['Ticket for failed sign-ins', 'Open a High-priority ticket for each failed sign-in. The rule engine cannot count repeats, so every failure counts.', 'fa-shield-alt', 'Ticket on failed sign-in', 'auth.login_failed', [], 'create_ticket', ['subject' => 'Failed sign-in: {summary}', 'details' => 'Event {event}. {summary}', 'priority' => 'High', 'client_id' => 0]],
            'onboarding-ticket' => ['Ticket when onboarding starts', 'Open a ticket so equipment and accounts are prepared for the new employee.', 'fa-user-plus', 'Ticket when onboarding starts', 'workflow.onboarding_started', [], 'create_ticket', ['subject' => 'Prepare equipment for new hire', 'details' => '{summary}', 'priority' => 'Medium', 'client_id' => 0]],
            'offboarding-ticket' => ['Ticket when offboarding starts', 'Open a ticket to collect equipment and revoke access.', 'fa-user-minus', 'Ticket when offboarding starts', 'workflow.offboarding_started', [], 'create_ticket', ['subject' => 'Collect equipment and revoke access', 'details' => '{summary}', 'priority' => 'High', 'client_id' => 0]],
            'workflow-failed' => ['Know when a workflow action fails', 'Notify the team when an automated workflow step fails.', 'fa-bell', 'Alert on failed workflow actions', 'workflow.action_failed', [], 'notify_user', ['message' => 'Workflow action failed: {summary}']],
            'change-created' => ['Announce new change requests', 'Notify the team when a change request is created.', 'fa-exchange-alt', 'Announce new changes', 'change.created', [], 'notify_user', ['message' => 'New change request: {summary}']],
        ];
        $out = [];
        foreach ($r as $key => [$title, $blurb, $icon, $name, $event, $conditions, $type, $config]) {
            $out[$key] = ['title' => $title, 'blurb' => $blurb, 'icon' => $icon, 'name' => $name, 'trigger_event' => $event, 'conditions' => $conditions, 'action_type' => $type, 'config' => $config];
        }

        return $out;
    }

    /** @return array<string,mixed>|null */
    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /** True when this edition really emits the event (the catalog marks reserved events 'planned'). */
    public static function eventIsLive(string $event): bool
    {
        $def = EventCatalog::get($event);

        return $def !== null && $def->since !== 'planned';
    }
}
