<?php

use App\Enums\AuditAcao;
use App\Models\ApiToken;
use App\Models\ApiTokenAcesso;
use App\Models\Company;
use App\Models\Organization;
use App\Support\Audit;
use App\Support\Fmt;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::admin'), Title('Conexões')] class extends Component {
    #[Url] public bool $revogadas = false;

    /** Modal "Nova conexão", em três passos: organização, empresa, sistema. */
    public int $passo = 1;
    public ?int $organizationId = null;
    public ?int $companyId = null;
    public string $nome = 'Costhub';

    /** Chave recém-criada: aparece uma única vez e some ao fechar o modal. */
    public ?string $chaveGerada = null;
    public ?string $chaveEmpresa = null;

    #[Computed]
    public function conexoes()
    {
        return ApiToken::query()
            ->with(['company:id,cnpj,razao_social,nome_fantasia', 'organization:id,name'])
            ->when(! $this->revogadas, fn ($q) => $q->active())
            ->orderByRaw('revoked_at is not null')
            ->latest()
            ->get();
    }

    /** Todas as organizações da plataforma, com quantas empresas e conexões ativas cada uma tem. */
    #[Computed]
    public function organizacoes()
    {
        return Organization::query()
            ->withCount(['companies', 'apiTokens as conexoes_count' => fn ($q) => $q->active()])
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /** Empresas da organização escolhida no passo 1, com as conexões ativas de cada uma. */
    #[Computed]
    public function empresas()
    {
        return $this->organizationId === null ? collect() : Company::query()
            ->where('organization_id', $this->organizationId)
            ->withCount(['apiTokens as conexoes_count' => fn ($q) => $q->active()])
            ->orderBy('razao_social')
            ->get(['id', 'organization_id', 'cnpj', 'razao_social', 'nome_fantasia', 'uf', 'ativo']);
    }

    #[Computed]
    public function organizacao(): ?Organization
    {
        return $this->organizacoes->firstWhere('id', $this->organizationId);
    }

    #[Computed]
    public function empresa(): ?Company
    {
        return $this->empresas->firstWhere('id', $this->companyId);
    }

    public function abrirNovaConexao(): void
    {
        $this->reset('passo', 'organizationId', 'companyId', 'nome');
        $this->resetValidation();

        // Com uma organização só, o primeiro passo não tem o que escolher.
        if ($this->organizacoes->count() === 1) {
            $this->escolherOrganizacao($this->organizacoes->first()->id);
        }

        Flux::modal('nova-conexao')->show();
    }

    public function escolherOrganizacao(int $id): void
    {
        $this->organizationId = Organization::findOrFail($id)->id;
        $this->companyId = null;
        $this->passo = 2;
        unset($this->empresas, $this->empresa);
    }

    public function escolherEmpresa(int $id): void
    {
        $this->companyId = Company::where('organization_id', $this->organizationId)->findOrFail($id)->id;
        $this->passo = 3;
        unset($this->empresa);
    }

    public function irPara(int $passo): void
    {
        // Só volta para passos já percorridos.
        if ($passo < $this->passo) {
            $this->passo = max(1, $passo);
        }
    }

    /** @return array{ativas: int, chamadas_7d: int, empresas: int} */
    #[Computed]
    public function resumo(): array
    {
        $ativas = ApiToken::query()->active();

        return [
            'ativas' => (clone $ativas)->count(),
            'empresas' => (clone $ativas)->whereNotNull('company_id')->distinct('company_id')->count('company_id'),
            'chamadas_7d' => ApiTokenAcesso::query()->where('created_at', '>=', now()->subDays(7))->count(),
        ];
    }

    public function criar(): void
    {
        $this->validate([
            'organizationId' => ['required', 'integer'],
            'companyId' => ['required', 'integer'],
            'nome' => ['required', 'string', 'max:60'],
        ], attributes: ['organizationId' => __('organização'), 'companyId' => __('empresa'), 'nome' => __('nome')]);

        // A empresa precisa ser da organização escolhida: o navegador não decide isso.
        $company = Company::where('organization_id', $this->organizationId)->findOrFail($this->companyId);
        $this->emitir($company, trim($this->nome));
    }

    /** Gera uma chave nova para a mesma empresa e revoga a anterior: o sistema precisa receber a nova. */
    public function novaChave(int $id): void
    {
        $antiga = ApiToken::query()->active()->whereNotNull('company_id')->with('company')->findOrFail($id);
        $this->revogarToken($antiga);
        $this->emitir($antiga->company, $antiga->name);
    }

    public function revogar(int $id): void
    {
        $this->revogarToken(ApiToken::query()->active()->findOrFail($id));
        unset($this->conexoes, $this->resumo);

        Flux::toast(text: __('Conexão revogada. O sistema que usava a chave perde o acesso na hora.'));
    }

    public function fecharChave(): void
    {
        $this->reset('chaveGerada', 'chaveEmpresa');
        Flux::modal('chave-gerada')->close();
    }

    private function emitir(Company $company, string $nome): void
    {
        [$token, $this->chaveGerada] = ApiToken::issueForCompany($company, $nome, Auth::user());
        $this->chaveEmpresa = $company->razao_social;
        Audit::record(AuditAcao::TokenCriado, $token->organization_id, descricao: "{$token->name} · {$company->razao_social}", dados: ['company_id' => $company->id, 'painel' => 'admin']);

        $this->reset('passo', 'organizationId', 'companyId', 'nome');
        unset($this->conexoes, $this->resumo);

        Flux::modal('nova-conexao')->close();
        Flux::modal('chave-gerada')->show();
    }

    private function revogarToken(ApiToken $token): void
    {
        $token->update(['revoked_at' => now()]);
        Audit::record(AuditAcao::TokenRevogado, $token->organization_id, descricao: $token->name, dados: ['company_id' => $token->company_id, 'painel' => 'admin']);
    }
}; ?>

<section class="mx-auto w-full max-w-6xl space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="space-y-1">
            <flux:heading size="xl" level="1">{{ __('Conexões') }}</flux:heading>
            <flux:text>{{ __('Chaves de acesso à API. Cada conexão fica presa a uma empresa e só enxerga as notas dela.') }}</flux:text>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="abrirNovaConexao">{{ __('Nova conexão') }}</flux:button>
    </div>

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        @foreach ([
            [__('Conexões ativas'), $this->resumo['ativas']],
            [__('Empresas conectadas'), $this->resumo['empresas']],
            [__('Chamadas nos últimos 7 dias'), $this->resumo['chamadas_7d']],
        ] as [$rotulo, $valor])
            <div class="border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900/40">
                <flux:text size="sm">{{ $rotulo }}</flux:text>
                <p class="mt-1 text-2xl font-semibold tabular-nums text-zinc-900 dark:text-white">{{ number_format($valor, 0, ',', '.') }}</p>
            </div>
        @endforeach
    </div>

    <div class="flex justify-end">
        <flux:switch wire:model.live="revogadas" :label="__('Mostrar revogadas')" align="left" />
    </div>

    <div class="border border-zinc-200 bg-white px-4 dark:border-zinc-700 dark:bg-zinc-900/40">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Conexão') }}</flux:table.column>
                <flux:table.column>{{ __('Empresa') }}</flux:table.column>
                <flux:table.column>{{ __('Chave') }}</flux:table.column>
                <flux:table.column>{{ __('Último uso') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Chamadas') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->conexoes as $c)
                    <flux:table.row :key="$c->id" @class(['opacity-60' => $c->revoked_at])>
                        <flux:table.cell>
                            <a href="{{ route('admin.conexoes.show', $c) }}" wire:navigate class="font-medium text-zinc-900 hover:underline dark:text-white">{{ $c->name }}</a>
                            <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('criada em :data', ['data' => Fmt::date($c->created_at)]) }}</div>
                        </flux:table.cell>
                        <flux:table.cell>
                            @if ($c->company)
                                <div class="max-w-72 truncate text-zinc-900 dark:text-white">{{ $c->company->razao_social }}</div>
                                <div class="text-xs text-zinc-500 tabular-nums dark:text-zinc-400">{{ Fmt::cnpj($c->company->cnpj) }}</div>
                            @else
                                <flux:badge size="sm" color="amber">{{ __('Organização inteira') }}</flux:badge>
                                <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $c->organization->name }}</div>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="font-mono text-xs">{{ $c->prefixo }}…</flux:table.cell>
                        <flux:table.cell>
                            {{ $c->last_used_at ? Fmt::relativo($c->last_used_at) : __('nunca') }}
                            @if ($c->ultimo_ip)
                                <div class="font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $c->ultimo_ip }}</div>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ number_format($c->total_chamadas, 0, ',', '.') }}</flux:table.cell>
                        <flux:table.cell align="end">
                            @if ($c->revoked_at)
                                <flux:badge size="sm" color="zinc">{{ __('Revogada') }}</flux:badge>
                            @else
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" :aria-label="__('Ações')" />
                                    <flux:menu>
                                        <flux:menu.item icon="eye" :href="route('admin.conexoes.show', $c)" wire:navigate>{{ __('Ver chamadas') }}</flux:menu.item>
                                        @if ($c->company_id)
                                            <flux:menu.item icon="arrow-path" wire:click="novaChave({{ $c->id }})" wire:confirm="{{ __('Gerar uma chave nova para :n? A atual para de funcionar na hora e o sistema precisa receber a nova.', ['n' => $c->name]) }}">{{ __('Gerar nova chave') }}</flux:menu.item>
                                        @endif
                                        <flux:menu.separator />
                                        <flux:menu.item icon="no-symbol" variant="danger" wire:click="revogar({{ $c->id }})" wire:confirm="{{ __('Revogar a conexão :n? O sistema que usa a chave perde o acesso na hora.', ['n' => $c->name]) }}">{{ __('Revogar') }}</flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="6" class="py-10 text-center">{{ __('Nenhuma conexão ainda. Clique em "Nova conexão" para gerar a chave de uma empresa.') }}</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    <flux:modal name="nova-conexao" class="w-full md:w-[44rem] md:max-w-[44rem]">
        @php
            $passos = [1 => __('Organização'), 2 => __('Empresa'), 3 => __('Sistema')];
            $busca = "busca === '' || \$el.dataset.busca.includes(busca.toLowerCase().normalize('NFD').replace(/[\\u0300-\\u036f]/g, '')) || (busca.replace(/\\D/g, '') !== '' && \$el.dataset.busca.includes(busca.replace(/\\D/g, '')))";
        @endphp
        <div class="space-y-6" x-data="{ busca: '' }" x-effect="$wire.passo; busca = ''">
            <div>
                <flux:heading size="lg">{{ __('Nova conexão') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Escolha a organização, depois a empresa. A chave só vai enxergar as notas dessa empresa.') }}</flux:text>
            </div>

            {{-- Passos: os já feitos viram atalho para voltar. --}}
            <ol class="flex items-center gap-2 text-sm" aria-label="{{ __('Passos') }}">
                @foreach ($passos as $n => $rotulo)
                    <li class="flex min-w-0 items-center gap-2 {{ $n < 3 ? 'flex-1' : '' }}">
                        <button type="button" wire:click="irPara({{ $n }})" @disabled($n >= $passo) class="flex min-w-0 items-center gap-2 {{ $n < $passo ? 'cursor-pointer' : 'cursor-default' }}" @if ($n === $passo) aria-current="step" @endif>
                            <span @class([
                                'flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold transition',
                                'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' => $n <= $passo,
                                'border border-zinc-300 text-zinc-400 dark:border-zinc-600 dark:text-zinc-500' => $n > $passo,
                            ])>
                                @if ($n < $passo)
                                    <flux:icon name="check" variant="micro" />
                                @else
                                    {{ $n }}
                                @endif
                            </span>
                            <span class="truncate {{ $n === $passo ? 'font-medium text-zinc-900 dark:text-white' : 'text-zinc-500 dark:text-zinc-400' }}">
                                @if ($n === 1 && $passo > 1 && $this->organizacao)
                                    {{ $this->organizacao->name }}
                                @elseif ($n === 2 && $passo > 2 && $this->empresa)
                                    {{ $this->empresa->nome_fantasia ?: $this->empresa->razao_social }}
                                @else
                                    {{ $rotulo }}
                                @endif
                            </span>
                        </button>
                        @if ($n < 3)
                            <span class="h-px flex-1 {{ $n < $passo ? 'bg-zinc-900 dark:bg-white' : 'bg-zinc-200 dark:bg-zinc-700' }}"></span>
                        @endif
                    </li>
                @endforeach
            </ol>

            @if ($passo === 1)
                <div class="surgir space-y-3" wire:key="passo-1">
                    <flux:input x-model="busca" icon="magnifying-glass" :placeholder="__('Buscar organização...')" :aria-label="__('Buscar organização')" />
                    <div class="max-h-80 space-y-1.5 overflow-y-auto">
                        @foreach ($this->organizacoes as $o)
                            <button
                                type="button"
                                wire:key="org-{{ $o->id }}"
                                wire:click="escolherOrganizacao({{ $o->id }})"
                                data-busca="{{ Str::lower(Str::ascii($o->name)) }}"
                                x-show="{{ $busca }}"
                                class="item-empresa group flex w-full cursor-pointer items-center gap-3 border border-zinc-200 px-3 py-3 text-start transition hover:border-zinc-400 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:border-zinc-500 dark:hover:bg-zinc-800"
                                style="--i: {{ $loop->index }}"
                            >
                                <span class="flex size-9 shrink-0 items-center justify-center bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                                    <flux:icon name="building-office-2" variant="mini" />
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate font-medium text-zinc-900 dark:text-white">{{ $o->name }}</span>
                                    <span class="block text-xs text-zinc-500 dark:text-zinc-400">
                                        {{ trans_choice(':n empresa|:n empresas', $o->companies_count, ['n' => $o->companies_count]) }}
                                        · {{ trans_choice(':n conexão ativa|:n conexões ativas', $o->conexoes_count, ['n' => $o->conexoes_count]) }}
                                    </span>
                                </span>
                                <flux:icon name="chevron-right" variant="mini" class="shrink-0 text-zinc-400 transition group-hover:translate-x-0.5 group-hover:text-zinc-700 dark:group-hover:text-zinc-200" />
                            </button>
                        @endforeach
                    </div>
                </div>
            @elseif ($passo === 2)
                <div class="surgir space-y-3" wire:key="passo-2">
                    <flux:input x-model="busca" icon="magnifying-glass" :placeholder="__('Buscar por razão social, nome fantasia ou CNPJ...')" :aria-label="__('Buscar empresa')" />
                    <div class="max-h-80 overflow-y-auto border border-zinc-200 dark:border-zinc-700">
                        <table class="w-full table-fixed text-sm">
                            <thead class="sticky top-0 z-10 bg-zinc-50 text-xs tracking-wide text-zinc-500 uppercase dark:bg-zinc-800 dark:text-zinc-400">
                                <tr>
                                    <th class="px-3 py-2 text-start font-medium">{{ __('Empresa') }}</th>
                                    <th class="w-44 px-3 py-2 text-start font-medium max-sm:hidden">{{ __('CNPJ') }}</th>
                                    <th class="w-14 px-3 py-2 text-start font-medium">{{ __('UF') }}</th>
                                    <th class="w-24 px-3 py-2 text-end font-medium">{{ __('Conexões') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                @forelse ($this->empresas as $e)
                                    <tr
                                        wire:key="empresa-{{ $e->id }}"
                                        wire:click="escolherEmpresa({{ $e->id }})"
                                        data-busca="{{ Str::lower(Str::ascii($e->razao_social.' '.$e->nome_fantasia)).' '.$e->cnpj }}"
                                        x-show="{{ $busca }}"
                                        tabindex="0"
                                        x-on:keydown.enter="$el.click()"
                                        class="item-empresa group cursor-pointer transition hover:bg-zinc-50 focus:bg-zinc-50 focus:outline-none dark:hover:bg-zinc-800 dark:focus:bg-zinc-800"
                                        style="--i: {{ $loop->index }}"
                                    >
                                        <td class="px-3 py-2.5">
                                            <span class="block truncate font-medium text-zinc-900 dark:text-white">{{ $e->razao_social }}</span>
                                            <span class="block truncate text-xs text-zinc-500 dark:text-zinc-400">
                                                {{ $e->nome_fantasia ?: '—' }}<span class="sm:hidden"> · {{ Fmt::cnpj($e->cnpj) }}</span>@unless ($e->ativo) · {{ __('inativa') }}@endunless
                                            </span>
                                        </td>
                                        <td class="px-3 py-2.5 whitespace-nowrap tabular-nums text-zinc-600 max-sm:hidden dark:text-zinc-300">{{ Fmt::cnpj($e->cnpj) }}</td>
                                        <td class="px-3 py-2.5 text-zinc-600 dark:text-zinc-300">{{ $e->uf->value }}</td>
                                        <td class="px-3 py-2.5 text-end">
                                            @if ($e->conexoes_count)
                                                <flux:badge size="sm" color="green">{{ $e->conexoes_count }}</flux:badge>
                                            @else
                                                <span class="text-zinc-400">—</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="px-3 py-8 text-center text-zinc-500 dark:text-zinc-400">{{ __('Esta organização ainda não tem empresas cadastradas.') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            @else
                <form wire:submit="criar" class="surgir space-y-5" wire:key="passo-3">
                    @if ($this->empresa)
                        <div class="flex items-center gap-3 border border-zinc-200 bg-zinc-50 px-4 py-3 dark:border-zinc-700 dark:bg-zinc-800/60">
                            <span class="flex size-10 shrink-0 items-center justify-center bg-white text-zinc-600 dark:bg-zinc-900 dark:text-zinc-300">
                                <flux:icon name="building-library" variant="mini" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-medium text-zinc-900 dark:text-white">{{ $this->empresa->razao_social }}</p>
                                <p class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ Fmt::cnpj($this->empresa->cnpj) }} · {{ $this->organizacao?->name }}</p>
                            </div>
                            <flux:button size="sm" variant="ghost" wire:click="irPara(2)">{{ __('Trocar') }}</flux:button>
                        </div>
                        @if ($this->empresa->conexoes_count)
                            <flux:callout icon="information-circle" color="amber">
                                <flux:callout.text>{{ trans_choice('Esta empresa já tem :n conexão ativa. Uma nova chave não revoga a anterior.|Esta empresa já tem :n conexões ativas. Uma nova chave não revoga as anteriores.', $this->empresa->conexoes_count, ['n' => $this->empresa->conexoes_count]) }}</flux:callout.text>
                            </flux:callout>
                        @endif
                    @endif

                    <flux:input wire:model="nome" :label="__('Sistema que vai usar a chave')" :description="__('Aparece na lista de conexões e no registro de chamadas.')" :placeholder="__('Ex.: Costhub')" maxlength="60" required autofocus />

                    <div class="flex justify-between gap-2">
                        <flux:button variant="ghost" icon="arrow-left" wire:click="irPara(2)">{{ __('Voltar') }}</flux:button>
                        <flux:button type="submit" variant="primary" icon="key">{{ __('Gerar chave') }}</flux:button>
                    </div>
                </form>
            @endif

            @if ($passo < 3)
                <div class="flex justify-between gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                    @if ($passo === 2)
                        <flux:button variant="ghost" icon="arrow-left" wire:click="irPara(1)">{{ __('Voltar') }}</flux:button>
                    @else
                        <span></span>
                    @endif
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancelar') }}</flux:button>
                    </flux:modal.close>
                </div>
            @endif
        </div>
    </flux:modal>

    <flux:modal name="chave-gerada" class="w-full md:w-[34rem]" :dismissible="false" :closable="false">
        <div class="space-y-5" x-data="{ copiada: false }">
            <div>
                <flux:heading size="lg">{{ __('Chave gerada') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Conexão com :e. Copie agora: a chave não será mostrada de novo.', ['e' => $chaveEmpresa]) }}</flux:text>
            </div>

            @if ($chaveGerada)
                <div class="flex items-stretch gap-2">
                    <code class="min-w-0 flex-1 border border-zinc-200 bg-zinc-50 px-3 py-2.5 font-mono text-sm break-all select-all dark:border-zinc-700 dark:bg-zinc-800" data-test="chave-gerada">{{ $chaveGerada }}</code>
                    <flux:button
                        icon="clipboard"
                        x-on:click="navigator.clipboard.writeText(@js($chaveGerada)); copiada = true; setTimeout(() => copiada = false, 2000)"
                    >
                        <span x-text="copiada ? @js(__('Copiada')) : @js(__('Copiar'))"></span>
                    </flux:button>
                </div>
            @endif

            <flux:callout icon="information-circle" color="zinc">
                <flux:callout.text>{{ __('No Costhub, cole a chave na conexão com o FinderXML. Ele confirma a empresa chamando GET /api/v1/conexao.') }}</flux:callout.text>
            </flux:callout>

            <div class="flex justify-end">
                <flux:button variant="primary" wire:click="fecharChave">{{ __('Já copiei') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</section>
