// Esqueleto de carregamento entre telas: enquanto o wire:navigate busca a próxima página, o conteúdo
// atual dá lugar a um esqueleto parecido com a tela de destino (resources/views/components/esqueleto/pagina.blade.php).

const ESPERA_MS = 120

const tipoDaTela = (path) => {
    if (/^\/documents\/?$/.test(path)) return 'notas'
    if (/^\/(documents|suppliers)\/[^/]+\/?$/.test(path)) return 'detalhe'
    if (/^\/companies\/(create|[^/]+\/edit)\/?$/.test(path)) return 'formulario'
    if (/^\/companies\/[^/]+(\/sped)?\/?$/.test(path)) return 'detalhe'
    if (/^\/(settings|organization\/integrations|organizations\/create)/.test(path)) return 'formulario'

    return 'lista'
}

let timer = null

document.addEventListener('livewire:navigate', (event) => {
    clearTimeout(timer)

    // Página que volta do cache do histórico aparece na hora: não precisa de esqueleto.
    if (event.detail?.cached) return

    const url = event.detail?.url
    const tipo = tipoDaTela(url instanceof URL ? url.pathname : new URL(url ?? location.href, location.origin).pathname)

    // Espera curta para não piscar o esqueleto em navegações que já chegam quase prontas.
    timer = setTimeout(() => {
        const conteudo = document.getElementById('conteudo')

        if (conteudo) {
            conteudo.dataset.carregando = tipo
            conteudo.setAttribute('aria-busy', 'true')
            conteudo.scrollTop = 0
        }
    }, ESPERA_MS)
})

document.addEventListener('livewire:navigated', () => clearTimeout(timer))
