<?php

namespace App\Http\Controllers\Admin;

use App\Automations\Context;
use App\Automations\Registry;
use App\Automations\Runner;
use App\Automations\Templates;
use App\Http\Controllers\Controller;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Client;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Quote;
use App\Models\Service;
use App\Models\Ticket;
use App\Support\Activity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Setup → Automations: the list, the builder, "Try it", and the log of runs.
 */
class AutomationController extends Controller
{
    public function __construct(private readonly Registry $registry) {}

    public function index(): View
    {
        $automations = Automation::query()
            ->withCount(['runs as recent_runs' => fn ($query) => $query->where('created_at', '>=', now()->subDays(30))])
            ->orderByDesc('is_active')->orderBy('name')
            ->get();

        return view('admin.automations.index', [
            'automations' => $automations,
            'registry' => $this->registry,
            'templates' => Templates::all(),
            'recentRuns' => AutomationRun::query()->with(['automation', 'subject'])->latest('updated_at')->limit(8)->get(),
        ]);
    }

    public function create(Request $request): View
    {
        $template = Templates::find((string) $request->query('template', ''));
        $automation = new Automation([
            'name' => $template['name'] ?? '',
            'trigger' => $template['trigger'] ?? 'invoice.overdue',
            'trigger_days' => $template['days'] ?? 7,
            'conditions' => $template['conditions'] ?? [],
            'steps' => $template['steps'] ?? [],
            'template' => $template !== null ? (string) $request->query('template') : null,
        ]);

        return $this->form($automation);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $automation = Automation::query()->create($data + ['is_active' => $request->boolean('activate')]);

        Activity::log('automation.created', "Automation \"{$automation->name}\" created", $automation);

        return redirect()->route('admin.automations.edit', $automation)
            ->with('status', $automation->is_active ? __('Automation saved and switched on.') : __('Automation saved. It is off until you switch it on.'));
    }

    public function edit(Automation $automation): View
    {
        return $this->form($automation);
    }

    public function update(Request $request, Automation $automation): RedirectResponse
    {
        $data = $this->validated($request);
        $automation->update($data + ($request->boolean('activate') ? ['is_active' => true] : []));

        Activity::log('automation.updated', "Automation \"{$automation->name}\" changed", $automation);

        return redirect()->route('admin.automations.edit', $automation)
            ->with('status', $automation->is_active ? __('Automation saved. It is on.') : __('Automation saved. It is off until you switch it on.'));
    }

    public function toggle(Automation $automation): RedirectResponse
    {
        $automation->update(['is_active' => ! $automation->is_active]);
        Activity::log('automation.toggled', "Automation \"{$automation->name}\" switched ".($automation->is_active ? 'on' : 'off'), $automation);

        return back()->with('status', $automation->is_active ? __(':name is on.', ['name' => $automation->name]) : __(':name is off. Runs that are waiting stop.', ['name' => $automation->name]));
    }

    public function destroy(Automation $automation): RedirectResponse
    {
        $automation->delete();
        Activity::log('automation.deleted', "Automation \"{$automation->name}\" deleted");

        return redirect()->route('admin.automations.index')->with('status', __('Automation deleted.'));
    }

    /**
     * "Try it": what the saved automation would do for one invoice, client or ticket, without doing it.
     */
    public function test(Request $request, Automation $automation, Runner $runner): RedirectResponse
    {
        $data = $request->validate(['subject' => ['required', 'string', 'max:190']]);
        $subject = $this->findSubject((string) $this->registry->trigger($automation->trigger)?->subject, trim($data['subject']));

        if ($subject === null) {
            return back()->withInput()->with('error', __('Nothing was found for “:search”.', ['search' => $data['subject']]));
        }

        return back()->withInput()->with('preview', ['subject' => Context::describe($subject)] + $runner->preview($automation, $subject));
    }

    public function runs(Request $request): View
    {
        $runs = AutomationRun::query()
            ->with(['automation', 'subject'])
            ->when($request->filled('automation'), fn ($query) => $query->where('automation_id', (int) $request->query('automation')))
            ->when(in_array($request->query('status'), [AutomationRun::DONE, AutomationRun::WAITING, AutomationRun::STOPPED, AutomationRun::FAILED], true), fn ($query) => $query->where('status', $request->query('status')))
            ->latest('updated_at')
            ->paginate(30)
            ->withQueryString();

        return view('admin.automations.runs', [
            'runs' => $runs,
            'automations' => Automation::query()->orderBy('name')->pluck('name', 'id'),
            'status' => (string) $request->query('status', ''),
        ]);
    }

    private function form(Automation $automation): View
    {
        $value = [
            'trigger' => $automation->trigger,
            'days' => $automation->trigger_days,
            'conditions' => $automation->conditions ?? [],
            'steps' => $automation->steps ?? [],
        ];
        $old = json_decode((string) old('definition', ''), true);

        return view('admin.automations.form', [
            'automation' => $automation,
            'editor' => ['defs' => $this->registry->editorDefinitions(), 'value' => is_array($old) ? $old : $value],
            'registry' => $this->registry,
            'runs' => $automation->exists ? $automation->runs()->with('subject')->latest('updated_at')->limit(8)->get() : collect(),
            'subjectHint' => $this->subjectHint((string) $this->registry->trigger($automation->trigger)?->subject),
        ]);
    }

    /**
     * @return array{name: string, trigger: string, trigger_days: int|null, conditions: list<array<string, mixed>>, steps: list<array<string, mixed>>}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'definition' => ['required', 'string', 'max:100000'],
            'template' => ['nullable', 'string', 'max:60'],
        ]);
        $definition = json_decode($data['definition'], true);

        if (! is_array($definition)) {
            throw ValidationException::withMessages(['definition' => __('The automation could not be read. Please try again.')]);
        }

        return ['name' => trim($data['name']), 'template' => $data['template'] ?? null] + $this->registry->clean($definition);
    }

    private function findSubject(string $type, string $search): ?Model
    {
        $id = ctype_digit($search) ? (int) $search : 0;

        return match ($type) {
            'invoice' => Invoice::query()->where('number', $search)->orWhere('id', $id)->first(),
            'client' => Client::query()->where('email', $search)->orWhere('id', $id)->first(),
            'service' => Service::query()->where('domain', $search)->orWhere('id', $id)->first(),
            'ticket' => Ticket::query()->where('number', ltrim($search, '#'))->first(),
            'order' => Order::query()->where('number', $search)->orWhere('id', $id)->first(),
            'domain' => Domain::query()->where('name', mb_strtolower($search))->first(),
            'quote' => Quote::query()->where('number', $search)->orWhere('id', $id)->first(),
            default => null,
        };
    }

    private function subjectHint(string $type): string
    {
        return match ($type) {
            'invoice' => __('Invoice number, for example :example', ['example' => (string) (Invoice::query()->whereNotNull('number')->latest('id')->value('number') ?? 'INV-0001')]),
            'client' => __('Client email address or number'),
            'service' => __('Service number or domain'),
            'ticket' => __('Ticket number'),
            'order' => __('Order number'),
            'domain' => __('Domain name'),
            'quote' => __('Quote number'),
            default => '',
        };
    }
}
