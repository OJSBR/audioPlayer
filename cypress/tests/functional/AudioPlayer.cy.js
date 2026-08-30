/**
 * @file cypress/tests/functional/AudioPlayer.cy.js
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com.br)
 * Distributed under the GNU GPL v3.
 *
 * Roda contra o conjunto de dados de teste da PKP por padrao. Para apontar para
 * outra instalacao, passe as variaveis:
 *   npx cypress run --spec 'plugins/generic/audioPlayer/cypress/tests/functional/*.cy.js' \
 *     --env contextPath=minhaeditora,adminUsername=admin,adminPassword=senha
 */

describe('audioPlayer plugin tests', function () {
	const contexto = Cypress.env('contextPath') || 'publicknowledge';
	const usuario = Cypress.env('adminUsername') || 'admin';
	const senha = Cypress.env('adminPassword') || 'admin';
	const LIMITE = 10;   // quantos livros do catalogo abrir procurando audio

	it('Enables the plugin and saves its settings', function () {
		cy.login(usuario, senha, contexto);

		abrirGradeDePlugins();

		// Idempotente de proposito: rodar a suite duas vezes nao pode DESLIGAR o
		// plugin que a primeira execucao ligou.
		cy.get('input[id^="select-cell-audioplayerplugin-enabled"]').as('ligar');
		cy.get('@ligar').then(($el) => {
			if (!$el.is(':checked')) {
				cy.get('@ligar').click();
				cy.get('div:contains(\'The plugin "Audio Player" has been enabled.\')');
				cy.waitJQuery();
			}
		});
		cy.reload();
		abrirGradeDePlugins();
		cy.get('input[id^="select-cell-audioplayerplugin-enabled"]').should('be.checked');

		abrirConfiguracoes();
		cy.wait(2000); // Avoid occasional failure due to form init taking time
		cy.get('form[id="audioPlayerSettings"] input[id^="autoplayNext"]').check();
		cy.get('form[id="audioPlayerSettings"] select[id^="defaultSpeed"]').select('1.5');
		cy.get('form[id="audioPlayerSettings"] button[id^="submitFormButton"]').click();
		cy.waitJQuery();

		// Reabrir e conferir: formulario que "salva" sem persistir e o defeito
		// classico de plugin_settings gravado com serialize() em vez de updateSetting().
		abrirConfiguracoes();
		cy.wait(2000);
		cy.get('form[id="audioPlayerSettings"] input[id^="autoplayNext"]').should('be.checked');
		cy.get('form[id="audioPlayerSettings"] select[id^="defaultSpeed"]').should('have.value', '1.5');
	});

	it('Builds the player from the audio publication format', function () {
		abrirLivroCom('.ojsbrAudioPlayerData', 'an audio publication format', () => {
			// O servidor entrega so os dados; a barra e as faixas sao montadas em JS.
			cy.get('.ojsbrAudioRow').should('have.length.at.least', 1);
			cy.get('.ojsbrAudioRowButton').first().click();
			// A barra e anexada ao body e a classe de estado vai no body tambem,
			// porque ela empurra o rodape da pagina quando abre.
			cy.get('.ojsbrAudioBar').should('exist');
			cy.get('body').should('have.class', 'ojsbrAudioBarOpen');
			cy.get('.ojsbrAudioTitle').should('not.have.text', '');
		});
	});

	it('Answers a Range request with 206, which is what seeking depends on', function () {
		abrirLivroCom('.ojsbrAudioPlayerData', 'an audio publication format', () => {
			cy.get('.ojsbrAudioRowButton').first().should('exist');
			cy.get('.ojsbrAudioPlayerData').invoke('attr', 'data-config').then((json) => {
				const faixa = JSON.parse(json).formats[0].tracks[0];
				expect(faixa.streamUrl, 'the track URL carries the streaming parameter').to.contain('audioStream');

				// Um proxy que engole o cabecalho Range devolve 200 aqui, e todo
				// arrasto na barra vira download do arquivo inteiro. Ja aconteceu.
				cy.request({url: faixa.streamUrl, headers: {Range: 'bytes=0-99'}}).then((r) => {
					expect(r.status).to.equal(206);
					expect(r.headers['content-range']).to.match(/^bytes 0-99\/\d+$/);
					expect(r.headers['accept-ranges']).to.equal('bytes');
				});

				// Faixa impossivel: 416, nao 200 com o arquivo inteiro.
				cy.request({url: faixa.streamUrl, headers: {Range: 'bytes=99999999999-'}, failOnStatusCode: false})
					.its('status').should('equal', 416);
			});
		});
	});

	it('Does not become a way around access control', function () {
		// O plugin so responde dentro de CatalogBookHandler::download, que ja rodou
		// a politica de acesso do OMP inteira: a politica de submissao publicada, o
		// formato disponivel, a publicacao publicada e o preco do arquivo. O que
		// este teste cobra e que ligar o parametro de streaming nao mude a resposta.
		//
		// O id invalido e derivado da URL real da faixa em vez de inventado: uma rota
		// montada a mao cai antes, no roteador, e o teste passaria sem ter exercitado
		// a politica de acesso nenhuma vez.
		abrirLivroCom('.ojsbrAudioPlayerData', 'an audio publication format', () => {
			cy.get('.ojsbrAudioPlayerData').invoke('attr', 'data-config').then((json) => {
				const streamUrl = JSON.parse(json).formats[0].tracks[0].streamUrl;
				const [caminho, query] = streamUrl.split('?');
				const negado = caminho.replace(/\/\d+$/, '/999999');

				cy.request({url: negado, failOnStatusCode: false}).its('status').then((semParametro) => {
					expect(semParametro, 'a file that does not belong to the format is refused').to.be.at.least(400);
					cy.request({url: `${negado}?${query}`, failOnStatusCode: false})
						.its('status').should('equal', semParametro);
				});
			});
		});
	});

	/**
	 * Leva ate a grade de plugins. Precisa ser chamado DE NOVO depois de cada
	 * reload: a pagina volta para a aba Appearance, e o conteudo das outras abas
	 * continua no DOM, apenas escondido — entao o seletor da grade ainda encontra
	 * o elemento e o clique nao faz nada, sem erro nenhum.
	 */
	function abrirGradeDePlugins() {
		cy.get('nav').contains('Settings').click();
		// Ensure submenu item click despite animation
		cy.get('nav').contains('Website').click({force: true});
		cy.get('button[id="plugins-button"]').click();
	}

	/**
	 * Abre o formulario de configuracoes do plugin na grade.
	 *
	 * A acao de configuracoes nao vive na linha visivel — o `row_actions` dela vem
	 * vazio — e sim na linha de extras, que so e revelada pelo `show_extras`. Clicar
	 * no link escondido com {force: true} ate ENCONTRA o elemento, mas o modal nao
	 * abre e o teste falha depois, longe da causa. Por isso: revelar, depois clicar.
	 * O toggle so e acionado quando o link ainda nao esta visivel, para o segundo
	 * uso no mesmo teste nao voltar a esconder a linha.
	 */
	function abrirConfiguracoes() {
		const link = 'a[id^="component-grid-settings-plugins-settingsplugingrid-category-generic-row-audioplayerplugin-settings-button-"]';
		// Consultar a partir do body a cada chamada, e nao guardar a linha: a grade
		// e redesenhada por AJAX e um <tr> capturado antes disso fica orfao — o
		// .find() nele nao acha mais nada, e o erro aponta para o seletor errado.
		cy.get('body').then(($b) => {
			if (!$b.find(link).filter(':visible').length) {
				cy.get('tr[id$="row-audioplayerplugin"] a.show_extras', {timeout: 20000}).click({force: true});
			}
		});
		cy.waitJQuery();
		cy.get(link).should('be.visible').click();
	}

	/**
	 * Abre o primeiro livro do catalogo que satisfaz o seletor. Percorrer o
	 * catalogo em vez de cravar um id mantem a suite util em qualquer base:
	 * o conjunto da PKP muda de versao para versao.
	 */
	function abrirLivroCom(seletor, descricao, aoAchar) {
		cy.visit('index.php/' + contexto + '/en/catalog');
		cy.get('a[href*="/catalog/book/"]').then(($as) => {
			const livros = Cypress._.uniq([...$as].map((a) => a.getAttribute('href')));
			const teto = Math.min(livros.length, LIMITE);
			if (livros.length > LIMITE) {
				cy.log(`catalogo com ${livros.length} livros; olhando so os ${LIMITE} primeiros`);
			}
			const tentar = (i) => {
				expect(i, `catalog has a book with ${descricao} among the first ${teto}`).to.be.lessThan(teto);
				cy.visit(livros[i]);
				cy.get('body').then(($b) => ($b.find(seletor).length ? aoAchar() : tentar(i + 1)));
			};
			tentar(0);
		});
	}
});
