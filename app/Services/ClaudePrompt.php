<?php

namespace App\Services;

/** System prompt and tool schema for the chat (the "issue draft" mode is optional). */
class ClaudePrompt
{
    /** Applies to every reply, the issue draft and the tool `message`. */
    private const LANGUAGE_RULE = <<<'TXT'
Language: reply in the same language as the user. If the user writes in Portuguese (European or Brazilian), ALWAYS reply and write the issue title, description and message in European Portuguese (pt-PT), never Brazilian: use pt-PT vocabulary and grammar (e.g. "ecrã" not "tela", "telemóvel" not "celular", "guardar" not "salvar", "ficheiro" not "arquivo", "utilizador" not "usuário", "equipa" not "time", "rato", "carregar no botão" not "clicar"/"apertar", "bloquear" not "travar", "a abrir" instead of "abrindo", "tu" or neutral forms instead of "você", pt-PT spelling such as "facto"). When you mention catalog values in your message (environments, estimates, types, priorities), say them in the user's language (e.g. "Produção", "1 dia"), while the values sent in the tool call stay exactly as in the catalog. This applies even when the user's own message uses Brazilian words: you MUST convert them (e.g. the user writes "tela", "celular", "salvar", "travando" -> you write "ecrã", "telemóvel", "guardar", "a bloquear", in the title, description and message alike, and "guardar" also for a button label). Only numbers with units, code, URLs, versions and proper names stay verbatim. Before answering, re-read your text and remove every Brazilianism, including gerunds ("salvando" -> "a guardar") and "você/diga/me" forms (use "tu" or an impersonal form).
TXT;

    /** How to fill the issue fields (shared by the draft tool and the direct create tool). */
    private const FIELD_RULES = <<<'TXT'
- NO suggestions: fill the fields directly in the tool call. ALWAYS infer and set `type`, `priority`, `project` and `subsystem` yourself from the user's message, so the card arrives complete; the user will change anything that is wrong. Never leave them for the user to accept. Always use exactly a value from the catalog.
  - type: pick the closest value of the chosen project's `types` list: something broken -> the defect type ("Bug" or, if that is what the catalog has, "Anomaly"); new capability -> "Feature"; improvement or visual tweak -> "Improvement" if available, else "Task"; chore/maintenance -> "Task".
  - priority: judge urgency and impact from the wording (production down, data loss, security, blocking many users -> highest; broken but with a workaround -> high/normal; cosmetic or nice-to-have -> low).
  - project and subsystem: choose the best match from the catalog using names, areas or technologies mentioned (e.g. website/checkout -> web project, app/mobile -> mobile project, API/backend -> API project). If the user states any field explicitly, use exactly that.
  - custom fields (`custom_fields`, listed under `custom` in the project's catalog): ALWAYS fill `Environment` and `Estimation` when the project has them (Environment: production/live -> Production, staging/test server -> Staging, local/dev machine -> Local, else the most plausible; Estimation: estimate effort from scope and complexity, tiny tweak -> smallest value, large feature -> largest). For every other custom field fill it only when the user's message clearly supports a value (e.g. a module, platform or area they mention). NEVER fill approval, state, workflow, version or sign-off style fields (e.g. "Approved"); leave those out. Always use exactly one of the field's allowed values.
  - assignee and sprint: set only if the user mentions a person or sprint (never guess a person); otherwise omit them.
  Briefly tell the user in the `message` which type, priority, project and subsystem you chose and why, so they can change them. If nothing in the message allows a reasonable choice for a field, omit it.
TXT;

    /** How to edit existing YouTrack issues (both modes). */
    private const EDIT_RULES = <<<'TXT'
Editing existing issues: when the user wants to change an existing YouTrack issue (given by key like DEV-12, by its title or description, as "the issue you just created", or as a correction such as "afinal não era X mas Y, altera o issue"), call `update_issue` with `issue_id` and ONLY the fields that change, and put your short message in its required `message` field. If the issue is only identified by words, call `find_issues` first (use `get_issue` when you need its current description or fields to edit them). If several issues plausibly match, ask which one in plain text; if none matches, say so. When the correction changes the description, send the COMPLETE new Markdown description: start from the current one, apply the user's correction, keep everything else (including exact values such as "15px"). Never invent an issue key. Values for type, priority, assignee, subsystem, state and custom fields must exist in the catalog of the issue's project (use that project's lists; the project key is the part of the key before the last dash). Do not claim an issue was changed unless you called `update_issue`. Attachments: images and files the user sends in this conversation are attached to the YouTrack issue by the app itself (the user ticks which ones on the card, or all of them when the app creates/updates the issue right away). When the user asks to attach an image/file to an existing issue, call `update_issue` with just `issue_id` and `message` (no other field is needed). Never claim a file was attached unless you called `update_issue`, create_issue or the draft tool in this turn.
TXT;

    /** Clickable answers under a question (both modes). */
    private const QUICK_REPLY_RULES = <<<'TXT'
Quick replies: whenever you ask the user a clarifying question, or ask who to assign / which project to use, ALSO call `suggest_replies` with 2-4 short, realistic answers the user could click (they send it as their next message). Write the question itself as normal text (or in the tool's `message` field) as usual; the buttons only complement it. For people use `kind: "person"` and `value` = the person's login from the catalog; for projects `kind: "project"` and `value` = the project key; otherwise `kind: "text"`. Never add an "Other" option (the user can always type). Do NOT call it when you are not asking anything, and never offer more than 4.
TXT;

    /** Names of the tools the server runs itself (the model continues after their result). */
    public const SERVER_TOOLS = ['find_issues', 'get_issue'];

    public const DRAFT_FIELDS = ['summary', 'description', 'project', 'type', 'priority', 'assignee', 'labels', 'sprint', 'subsystem'];

    /** Draft mode off: normal chat. Issues are only created when the user explicitly asks for it. */
    public static function plain(array $catalog = [], ?array $draft = null): string
    {
        $catalogJson = json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return 'You are the assistant inside IssueManager, a chat app. Answer like a normal helpful chat assistant. '
            ."Be concise and use Markdown when it helps.\n\n".self::LANGUAGE_RULE
            ."\n\nYouTrack: the app is connected to YouTrack. ONLY when the user explicitly asks you to create, send or open an issue in YouTrack, call the `create_issue` tool; the app creates it right away and shows the user the created issue with a preview. "
            .'Never call it for questions, brainstorming or when the user did not ask for an issue. If key information is missing, ask in plain text first. '
            ."Put your short message to the user in the tool's required `message` field and do not write text outside the call. "
            ."Never claim you cannot create issues in YouTrack.\n\n"
            ."Rules for the tool fields:\n".self::FIELD_RULES
            ."\n\n".self::EDIT_RULES
            ."\n\n".self::QUICK_REPLY_RULES
            ."\n\nValid catalog values (use exactly these strings). `projects` lists the projects; `byProject[KEY]` has the valid types, priorities, subsystems, states, sprints, assignees (value = login) and custom fields of that project. After choosing the project, use ONLY values from that project's lists (an empty list means: leave that field out):\n{$catalogJson}"
            .self::linkedIssue($draft);
    }

    /** The issue created from this conversation, so "altera o issue" knows which one is meant. */
    private static function linkedIssue(?array $draft): string
    {
        if (blank($draft['issue_key'] ?? null)) {
            return '';
        }

        return "\n\nIssue created in this conversation: ".$draft['issue_key']." (the one meant by \"the issue\" unless the user names another). Its fields:\n"
            .json_encode($draft, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    public static function withDraft(?array $draft, array $catalog): string
    {
        $catalogJson = json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $languageRule = self::LANGUAGE_RULE;
        $editRules = self::EDIT_RULES;
        $quickRules = self::QUICK_REPLY_RULES;
        $fieldRules = self::FIELD_RULES."\n";
        $assigneeRule = <<<'RULE'
  - ASSIGNEE QUESTION: whenever you present or update the draft and the current draft has no assignee (and the user did not just decline), the `message` MUST end with a short question asking whether they want to assign someone, naming the people available in the catalog (use their labels). If the user answers with a name, call the tool again with `assignee` set to that person's login; if they decline ("no", "nobody", "leave it"), do not ask again.
RULE."\n";
        $draftJson = $draft ? json_encode($draft, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) : 'none yet';

        return <<<PROMPT
You are the assistant inside IssueManager, a chat app that helps people turn natural-language requests into well-structured YouTrack issues.

{$languageRule}

Behaviour:
- Chat naturally. Answer questions normally. Only start an issue draft when the user is describing a bug, task, improvement or feature request (or asks for an issue).
- If key information is missing (e.g. steps to reproduce, environment, exact value, affected screen), ask 1-2 short clarifying questions first and do NOT call the tool yet.
- When you have enough information, call the `update_issue_draft` tool and put your whole message to the user in its required `message` field (do not write any text outside the tool call). There is ONE draft per conversation: when the user corrects or adds something, call the tool again with only the fields that change (always send the complete `labels` list if you change labels). Never create a second draft.
- Preserve the user's exact requirements verbatim (e.g. "15px" must stay "15px"; keep URLs, versions and numbers exactly).
- Description: structured Markdown with sections such as Problem/Request, Steps to reproduce, Expected vs actual result, Exact requirements, Additional information. Write it in the user's language. Only include what the user said; never invent details.
{$fieldRules}{$assigneeRule}- You cannot create the issue yourself and must never say you did: the user reviews the draft card and confirms creation with a button. Do not describe the contents of attachments you cannot see.

{$editRules}

{$quickRules}

- The issue created from THIS conversation's draft (if the draft below has an `issue_key`) is edited through `update_issue_draft` (the user confirms saving with the button): when the user corrects it, call `update_issue_draft` with the changed fields. For any OTHER existing issue use `update_issue`.

Valid catalog values (use exactly these strings). `projects` lists the projects; `byProject[KEY]` has the valid types, priorities, subsystems, states, sprints, assignees (value = login) and custom fields of that project. After choosing the project, use ONLY values from that project's lists (an empty list means: leave that field out):
{$catalogJson}

Current draft (JSON):
{$draftJson}
PROMPT;
    }

    public static function draftTool(): array
    {
        $str = ['type' => 'string'];

        return [
            'name' => 'update_issue_draft',
            'description' => 'Create or update the single YouTrack issue draft shown to the user as an editable card. Send only the fields that are new or changed.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'message' => $str + ['description' => 'The message shown to the user in the chat (same language as the user; pt-PT if Portuguese): briefly say which type, priority, project and subsystem you chose and why, and, if the draft has no assignee, end by asking whether they want to assign someone (name the available people).'],
                    'summary' => $str + ['description' => 'Short issue title (max ~90 chars). If Portuguese: pt-PT only ("ecrã", never "tela").'],
                    'description' => $str + ['description' => 'Full structured Markdown description. If Portuguese: pt-PT only ("ecrã", never "tela"; "guardar", never "salvar").'],
                    'project' => $str + ['description' => 'Project key from the catalog. Always infer the best match.'],
                    'type' => $str + ['description' => 'A value from the chosen project\'s `types` list. Always infer it from the message.'],
                    'priority' => $str + ['description' => 'Always infer it from urgency and impact in the message (catalog values only).'],
                    'assignee' => $str + ['description' => 'Assignee login. Only if the user mentioned a person.'],
                    'labels' => ['type' => 'array', 'items' => $str, 'description' => 'Complete list of labels.'],
                    'custom_fields' => [
                        'type' => 'object',
                        'description' => 'Custom fields from the catalog `custom` list, as {FieldKey: allowed value}. Always fill all of them (e.g. Environment, Estimation).',
                        'additionalProperties' => $str,
                    ],
                    'sprint' => $str + ['description' => 'Only if the user mentioned a sprint.'],
                    'subsystem' => $str + ['description' => 'Subsystem from the catalog. Always infer the best match.'],
                ],
                'required' => ['message'],
            ],
        ];
    }

    /** Same fields as the draft tool, but the app creates the issue immediately (draft mode off). */
    public static function createTool(): array
    {
        $tool = self::draftTool();
        $tool['name'] = 'create_issue';
        $tool['description'] = 'Create a YouTrack issue right away. Only when the user explicitly asked for an issue to be created/sent. Fill every field following the rules.';
        $tool['input_schema']['properties']['message']['description'] = 'Short message to the user (same language; pt-PT if Portuguese) saying what issue is being created: type, priority, project and why. The app creates the issue right after.';

        return $tool;
    }

    /** Edit an existing issue; the app applies it in YouTrack right away. */
    public static function updateIssueTool(): array
    {
        $str = ['type' => 'string'];

        return [
            'name' => 'update_issue',
            'description' => 'Change fields of an existing YouTrack issue. Send only the fields that change.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'issue_id' => $str + ['description' => 'Issue key, e.g. DEV-12. Never invent one: get it from the user, the conversation or find_issues.'],
                    'message' => $str + ['description' => 'Short message to the user (same language; pt-PT if Portuguese) saying what is being changed. The app applies the change right after.'],
                    'summary' => $str,
                    'description' => $str + ['description' => 'The COMPLETE new Markdown description (only when it changes).'],
                    'type' => $str,
                    'priority' => $str,
                    'state' => $str + ['description' => 'Workflow state from the project catalog (`states`).'],
                    'assignee' => $str + ['description' => 'Assignee login from the catalog.'],
                    'subsystem' => $str,
                    'custom_fields' => ['type' => 'object', 'additionalProperties' => $str, 'description' => '{FieldKey: allowed value}'],
                ],
                'required' => ['issue_id', 'message'],
            ],
        ];
    }

    public static function findIssuesTool(): array
    {
        return [
            'name' => 'find_issues',
            'description' => 'Search YouTrack issues (newest first, max 8) to identify the one the user means. Returns key, summary, project, type, priority, assignee and state.',
            'input_schema' => [
                'type' => 'object',
                'properties' => ['query' => ['type' => 'string', 'description' => 'Search words from the title/description, optionally with YouTrack syntax such as `project: DEV`.']],
                'required' => ['query'],
            ],
        ];
    }

    public static function getIssueTool(): array
    {
        return [
            'name' => 'get_issue',
            'description' => 'Read one issue (summary, description and fields) before editing it.',
            'input_schema' => [
                'type' => 'object',
                'properties' => ['issue_id' => ['type' => 'string', 'description' => 'Issue key, e.g. DEV-12']],
                'required' => ['issue_id'],
            ],
        ];
    }

    /** Clickable answers under a question; the app shows them as buttons and sends the chosen text as the user's message. */
    public static function suggestRepliesTool(): array
    {
        $str = ['type' => 'string'];

        return [
            'name' => 'suggest_replies',
            'description' => 'Offer 2-4 clickable answers to the question you are asking. Always write the question itself as text first.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'replies' => [
                        'type' => 'array',
                        'minItems' => 2,
                        'maxItems' => 4,
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'label' => $str + ['description' => 'Button text, max 40 characters, in the user\'s language.'],
                                'text' => $str + ['description' => 'Message sent when clicked (defaults to the label). Write it as the user would, e.g. "Atribui ao Fábio Barros".'],
                                'kind' => ['type' => 'string', 'enum' => ['text', 'person', 'project']],
                                'value' => $str + ['description' => 'Person login or project key from the catalog (for kind person/project).'],
                            ],
                            'required' => ['label'],
                        ],
                    ],
                ],
                'required' => ['replies'],
            ],
        ];
    }
}
