const USAR_MOCK_FIXTURES = false; // ✅ Ativa mock para testes

let jogosDisponiveis = [];
let jogosSelecionados = [];
let jogosCancelados = [];


console.log(wpfpData);

async function carregarCamposSelecionadosDoPool() {
    const paisSelect = document.getElementById('wpfp_country');
    const ligaSelect = document.getElementById('wpfp_liga_brasileira');
    const anoSelect = document.getElementById('wpfp_ano_temporada');


    const pais = paisSelect?.value;
    const liga = ligaSelect?.value;
    const ano = anoSelect?.value;

    const erroDiv = document.getElementById('wpfp_api_error');

    if (!pais || !liga || !ano) {
        console.warn("País, liga ou ano não selecionado. Ignorando carregarCamposSelecionadosDoPool");
        return;
    }



    paisSelect.value = pais;

    // 1. Buscar ligas do país
    try {
        const res = await fetch(`https://v3.football.api-sports.io/leagues?country=${encodeURIComponent(pais)}`, {
            headers: {
                "x-apisports-key": wpfpData.apiKey,
                "x-rapidapi-host": wpfpData.apiHost
            }
        });

        const data = await res.json();

        ligaSelect.innerHTML = '<option value="" disabled selected>-- Selecione uma liga --</option>';
        data.response.forEach(item => {
            const opt = document.createElement('option');
            opt.value = item.league.id;
            opt.textContent = `${item.league.name} (${item.league.type})`;
            opt.dataset.years = JSON.stringify(item.seasons.map(s => s.year));
            ligaSelect.appendChild(opt);
        });
        ligaSelect.disabled = false;
        ligaSelect.value = liga;

    } catch (err) {
        console.error("❌ Erro ao buscar ligas:", err);
        if (erroDiv) {
            erroDiv.innerHTML = `<span style="color:red;">❌ Erro ao buscar ligas. Verifique a conexão ou chave da API.</span>`;
            erroDiv.style.display = 'block';
        }
        return;
    }

    // 2. Temporadas
    const option = ligaSelect.selectedOptions[0];
    const anos = JSON.parse(option?.dataset.years || '[]');
    anoSelect.innerHTML = '<option value="" disabled selected>-- Selecione o ano --</option>';
    anos.sort((a, b) => b - a).forEach(anoItem => {
        const opt = document.createElement('option');
        opt.value = anoItem;
        opt.textContent = anoItem;
        anoSelect.appendChild(opt);
    });
    anoSelect.disabled = false;
    anoSelect.value = ano;

    // 3. Buscar ou simular jogos
   // 3. Buscar ou simular jogos
const usarMock = typeof USAR_MOCK_FIXTURES !== 'undefined' && USAR_MOCK_FIXTURES === true;

if (usarMock) {
    if (typeof window.mockFixturesResponse !== 'undefined') {
        console.warn("⚠️ Usando dados mockados");

        jogosDisponiveis = window.mockFixturesResponse.response || [];

        // ✅ Força dinamicamente o ano para 2025
        jogosDisponiveis.forEach(jogo => {
            if (jogo.fixture?.date) {
                const data = new Date(jogo.fixture.date);
                data.setFullYear(2025);
                jogo.fixture.date = data.toISOString();
            }
        });

        console.log("✔️ Mock carregado. Datas dos jogos:", jogosDisponiveis.map(j => j.fixture.date));

        aplicarDadosDoPool();
        return;
    } else {
        console.warn("⚠️ Mock está ativado mas não foi carregado corretamente.");
        return; // ❌ Impede fallback na API!
    }
}




    // 4. Buscar jogos reais
    try {
        const res = await fetch(`https://v3.football.api-sports.io/fixtures?league=${liga}&season=${ano}`, {
            headers: {
                "x-apisports-key": wpfpData.apiKey,
                "x-rapidapi-host": wpfpData.apiHost
            }
        });

        const data = await res.json();

        // Limite de requisições atingido
        if (data.errors?.requests) {
            if (erroDiv) {
                erroDiv.innerHTML = `<span style="color:red;">🚫 Limite da API atingido. Acesse o <a href="https://dashboard.api-football.com" target="_blank">painel da API</a> para atualizar seu plano.</span>`;
                erroDiv.style.display = 'block';
            }
            return;
        }

        jogosDisponiveis = data.response || [];
        aplicarDadosDoPool();
        setTimeout(atualizarCalculosPremiacao, 200); // força o cálculo mesmo se inputs forem preenchidos depois


    } catch (err) {
        console.error("❌ Erro ao buscar fixtures:", err);
        if (erroDiv) {
            erroDiv.innerHTML = `<span style="color:red;">❌ Erro ao buscar jogos. Tente novamente mais tarde.</span>`;
            erroDiv.style.display = 'block';
        }
    }
}

function aplicarDadosDoPool() {
    jogosSelecionados = Array.isArray(wpfpData.selectedGames)
        ? wpfpData.selectedGames.map(Number).filter(id => jogosDisponiveis.some(j => j.fixture.id === id))
        : [];

   jogosCancelados = Array.isArray(wpfpData.cancelledGames)
      ? wpfpData.cancelledGames.map(Number).filter(id => jogosDisponiveis.some(j => j.fixture.id === id))
       : [];

  

    renderizarTabelaJogosDisponiveis();
    renderizarTabelaSelecionados();
    atualizarContador();
    atualizarCalculosPremiacao();
    validarCamposObrigatorios();
}





function renderizarTabelaSelecionados() {
    const container = document.getElementById('wpfp-jogos-selecionados');
    container.innerHTML = '';

    if (!jogosSelecionados.length) {
        container.innerHTML = '<p style="color:#777;font-style:italic">🟡 Nenhum jogo selecionado.</p>';
        return;
    }

    const diasSemana = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
    const table = document.createElement('table');
    table.className = 'wpfp-table';

    table.innerHTML = `
        <thead>
            <tr>
                <th>ID</th>
                <th>Data</th>
                <th>Times</th>
                <th>Gols</th>
                <th>Vencedor</th>
                <th>Status</th>
                <th>Ações</th>
            </tr>
        </thead>
    `;

    const tbody = document.createElement('tbody');

    jogosSelecionados.forEach(id => {
        const jogo = jogosDisponiveis.find(j => j.fixture.id === id);
        const isCanceladoApi = jogo?.fixture.status.short === 'CANC';
        const cancelado = isCanceladoApi || jogosCancelados.includes(id);

        // ⚠️ Se o jogo não veio da API
        if (!jogo) {
            const tr = document.createElement('tr');
            tr.className = cancelado ? 'cancelado' : '';
            tr.innerHTML = `
                <td>${id}</td>
                <td colspan="6" style="color:#a00; font-style:italic;">
                    ⚠️ Jogo ID ${id} não está mais disponível na API.
                    ${cancelado ? '<br><strong>Cancelado pelo administrador.</strong>' : ''}
                </td>
            `;
            tbody.appendChild(tr);
            return;
        }

        const { fixture, teams, goals } = jogo;
        const dataObj = new Date(fixture.date);
        const dia = diasSemana[dataObj.getDay()];
        const dataFormatada = `${dataObj.toLocaleDateString('pt-BR')} ${dataObj.toLocaleTimeString('pt-BR').slice(0, 5)} (${dia})`;

        const vencedor = teams.away.winner === null
            ? '—'
            : teams.away.winner
                ? `${teams.away.name} 🟢`
                : `${teams.home.name} 🟢`;

        const tr = document.createElement('tr');
        tr.className = cancelado ? 'cancelado' : '';

      /*  tr.innerHTML = `
            <td>${id}</td>
            <td>${dataFormatada}</td>
            <td>
                <img src="${teams.home.logo}" alt="" style="max-width:20px; vertical-align:middle; margin-right:5px;"> ${teams.home.name}
                x
                <img src="${teams.away.logo}" alt="" style="max-width:20px; vertical-align:middle; margin-left:5px;"> ${teams.away.name}
            </td>
            <td>${goals.home ?? '-'} x ${goals.away ?? '-'}</td>
            <td>${vencedor}</td>
            <td>${fixture.status.short}</td>
            <td>
                <button type="button" class="wpfp-toggle-cancel" data-id="${id}">${cancelado ? '❌ Desfazer' : '⚠️ Cancelar'}</button>
                <button type="button" class="wpfp-remover-jogo" data-id="${id}">🗑️ Remover</button>
            </td>
        `;
        */
         tr.innerHTML = `
            <td>${id}</td>
            <td>${dataFormatada}</td>
            <td>
                <img src="${teams.home.logo}" alt="" style="max-width:20px; vertical-align:middle; margin-right:5px;"> ${teams.home.name}
                x
                <img src="${teams.away.logo}" alt="" style="max-width:20px; vertical-align:middle; margin-left:5px;"> ${teams.away.name}
            </td>
            <td>${goals.home ?? '-'} x ${goals.away ?? '-'}</td>
            <td>${vencedor}</td>
            <td>${fixture.status.short}</td>
            <td>
               
                <button type="button" class="wpfp-remover-jogo" data-id="${id}">🗑️ Remover</button>
            </td>
        `;

        tbody.appendChild(tr);
    });

    table.appendChild(tbody);
    container.appendChild(table);

    // Eventos dos botões
    container.querySelectorAll('.wpfp-remover-jogo').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = parseInt(btn.dataset.id);
            jogosSelecionados = jogosSelecionados.filter(j => j !== id);
            jogosCancelados = jogosCancelados.filter(j => j !== id);
            renderizarTabelaSelecionados();
            atualizarContador();
            atualizarCalculosPremiacao();
            validarCamposObrigatorios();
        });
    });

    container.querySelectorAll('.wpfp-toggle-cancel').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = parseInt(btn.dataset.id);
            if (jogosCancelados.includes(id)) {
                jogosCancelados = jogosCancelados.filter(j => j !== id);
            } else {
                jogosCancelados.push(id);
            }
            renderizarTabelaSelecionados();
            atualizarCalculosPremiacao();
            validarCamposObrigatorios();
        });
    });
}


function renderizarTabelaJogosDisponiveis() {
    const tabelaWrapper = document.getElementById('wpfp-tabela-jogos');
   
    tabelaWrapper.innerHTML = '';

    if (!jogosDisponiveis.length) {
        tabelaWrapper.innerHTML = '<p style="color:#777;font-style:italic">⚠️ Nenhum jogo encontrado.</p>';
        return;
    }

    const diasSemana = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
    const table = document.createElement('table');
    table.className = 'wpfp-table';

    table.innerHTML = `
        <thead>
            <tr>
                <th>Selecionar</th>
                <th>#</th>
                <th>ID</th>
                <th>Data</th>
                <th>Times</th>
                <th>Status</th>
            </tr>
        </thead>
    `;

    const tbody = document.createElement('tbody');

  jogosDisponiveis.forEach((jogo, idx) => {
    const { fixture, teams } = jogo;
    const id = fixture.id;
    const dataObj = new Date(fixture.date);
    const dia = diasSemana[dataObj.getDay()];
    const dataFormatada = `${dataObj.toLocaleDateString('pt-BR')} ${dataObj.toLocaleTimeString('pt-BR').slice(0, 5)} (${dia})`;

    const checked = jogosSelecionados.includes(id) ? 'checked' : '';
    const isApiCancelado = fixture.status.short === 'CANC';
    const status = fixture.status.short === 'CANC' ? '❌ Cancelado' : fixture.status.short;


    const tr = document.createElement('tr');
    tr.className = isApiCancelado ? 'api-cancelado' : '';
    tr.innerHTML = `
        <td><input type="checkbox" data-id="${id}" ${checked} ${isApiCancelado ? 'disabled' : ''}></td>
        <td>${idx + 1}</td>
        <td>${id}</td>
        <td>${dataFormatada}</td>
        <td>
            <img src="${teams.home.logo}" alt="" style="max-width:20px; vertical-align:middle; margin-right:5px;"> ${teams.home.name}
            x
            <img src="${teams.away.logo}" alt="" style="max-width:20px; vertical-align:middle; margin-left:5px;"> ${teams.away.name}
        </td>
        <td>${status}</td>
    `;
    tbody.appendChild(tr);
    });


    table.appendChild(tbody);

    // Scroll wrapper com cabeçalho fixo
    const scrollWrapper = document.createElement('div');
    scrollWrapper.className = 'scroll-wrapper';
    scrollWrapper.appendChild(table);

    tabelaWrapper.appendChild(scrollWrapper);

    // Rodapé com contador
    const footer = document.createElement('div');
    footer.className = 'wpfp-jogos-footer';
    footer.style.marginTop = '10px';
    footer.innerHTML = `
        <span><strong id="contador-jogos">${jogosSelecionados.length}</strong> de 10 jogos selecionados</span>
    `;
    tabelaWrapper.appendChild(footer);

    // Eventos de checkbox
    tbody.querySelectorAll('input[type="checkbox"]').forEach(input => {
        input.addEventListener('change', () => {
            const id = parseInt(input.dataset.id);
            if (input.checked) {
                if (!jogosSelecionados.includes(id)) {
                    if (jogosSelecionados.length < 10) {
                        jogosSelecionados.push(id);
                    } else {
                        alert("⚠️ Limite de 10 jogos atingido!");
                        input.checked = false;
                        return;
                    }
                }
            } else {
                jogosSelecionados = jogosSelecionados.filter(j => j !== id);
                jogosCancelados = jogosCancelados.filter(j => j !== id);
            }
            renderizarTabelaSelecionados();
            atualizarContador();
            atualizarCalculosPremiacao();
            validarCamposObrigatorios();
        });
    });

    atualizarContador();
}



document.addEventListener('DOMContentLoaded', () => {

    


  console.log('✅ DOM carregado');
  console.log(wpfpData);

  const usarMock = typeof wpfpMock !== 'undefined'; // <- Detecta se mock está disponível

  const paisSelect = document.getElementById('wpfp_country');
  const ligaSelect = document.getElementById('wpfp_liga_brasileira');
  const anoSelect = document.getElementById('wpfp_ano_temporada');



// ⚙️ Botão para alternar entre MOCK e API real
function criarBotaoAlternarMock() {
  const btn = document.createElement('button');
  btn.className = 'button button-secondary';
  btn.style.margin = '10px 0';
  btn.id = 'btnToggleMock';

  const wrapper = document.querySelector('.wpfp-field');
  if (!wrapper) return;

  // Estado atual
  const usandoMock = localStorage.getItem('wpfp_usar_mock') === 'true';
  atualizarTextoBotao(btn, usandoMock);

  btn.addEventListener('click', () => {
    const novoEstado = !(localStorage.getItem('wpfp_usar_mock') === 'true');
    localStorage.setItem('wpfp_usar_mock', novoEstado);
    atualizarTextoBotao(btn, novoEstado);
    alert(`Modo ${novoEstado ? 'MOCK' : 'API real'} ativado. Recarregando...`);
    window.location.reload();
  });

  wrapper.parentElement.insertBefore(btn, wrapper);
}

function atualizarTextoBotao(btn, usandoMock) {
  btn.textContent = usandoMock ? '🔁 Usando MOCK (clique para API real)' : '🌐 Usando API real (clique para MOCK)';
  btn.style.backgroundColor = usandoMock ? '#ffc107' : '#0d6efd';
  btn.style.color = '#fff';
}



  /**
   * MOCK Fallback: Países
   */
  function carregarPaisesMock() {
    paisSelect.innerHTML = '<option value="">-- Escolha um país --</option>';
    wpfpMock.paises.forEach(pais => {
      const opt = document.createElement('option');
      opt.value = pais;
      opt.textContent = pais;
      paisSelect.appendChild(opt);
    });

    if (wpfpData.selectedCountry) {
      paisSelect.value = wpfpData.selectedCountry;
      paisSelect.dispatchEvent(new Event('change'));
      setTimeout(() => carregarCamposSelecionadosDoPool(), 500);
    }
  }

  /**
   * API Oficial: Países
   */
  function carregarPaises() {
    try {
      if (!paisSelect) throw new Error("Elemento #wpfp_country não está presente no DOM.");

      fetch("https://v3.football.api-sports.io/leagues?current=true", {
        headers: {
          "x-apisports-key": wpfpData.apiKey,
          "x-rapidapi-host": wpfpData.apiHost
        }
      })
        .then(res => res.json())
        .then(data => {
          const paises = new Set(data.response.map(item => item.country.name));
          paisSelect.innerHTML = '<option value="">-- Escolha um país --</option>';

          [...paises].sort().forEach(pais => {
            const opt = document.createElement('option');
            opt.value = pais;
            opt.textContent = pais;
            paisSelect.appendChild(opt);
          });

          if (wpfpData.selectedCountry) {
            paisSelect.value = wpfpData.selectedCountry;
            paisSelect.dispatchEvent(new Event('change'));
            setTimeout(() => carregarCamposSelecionadosDoPool(), 500);
          }
        })
        .catch(err => {
          console.error("❌ Erro ao buscar países:", err);
        });

    } catch (e) {
      console.error("❌ Falha em carregar países:", e.message);
    }
  }

  /**
   * Evento ao alterar país
   */
  paisSelect.addEventListener('change', () => {
    const pais = paisSelect.value;
    ligaSelect.disabled = true;
    anoSelect.disabled = true;
    ligaSelect.innerHTML = '<option>🔄 Carregando ligas...</option>';
    anoSelect.innerHTML = '<option value="">-- Escolha uma liga primeiro --</option>';

    if (usarMock) {
      ligaSelect.innerHTML = '<option value="" disabled selected>-- Selecione uma liga --</option>';
      (wpfpMock.ligas[pais] || []).forEach(liga => {
        const opt = document.createElement('option');
        opt.value = liga.id;
        opt.textContent = liga.name;
        opt.dataset.ligaNome = liga.name;
        ligaSelect.appendChild(opt);
      });
      ligaSelect.disabled = false;

      if (wpfpData.selectedLeague) {
        ligaSelect.value = wpfpData.selectedLeague;
        ligaSelect.dispatchEvent(new Event('change'));
      }
    } else {
      fetch(`https://v3.football.api-sports.io/leagues?country=${encodeURIComponent(pais)}`, {
        headers: {
          "x-apisports-key": wpfpData.apiKey,
          "x-rapidapi-host": wpfpData.apiHost
        }
      })
        .then(res => res.json())
        .then(data => {
          ligaSelect.innerHTML = '<option value="" disabled selected>-- Selecione uma liga --</option>';
          data.response.forEach(item => {
            const opt = document.createElement('option');
            opt.value = item.league.id;
            opt.textContent = `${item.league.name} (${item.league.type})`;
            opt.dataset.years = JSON.stringify(item.seasons.map(s => s.year));
            ligaSelect.appendChild(opt);
          });
          ligaSelect.disabled = false;

          if (wpfpData.selectedLeague) {
            ligaSelect.value = wpfpData.selectedLeague;
            ligaSelect.dispatchEvent(new Event('change'));
          }
        });
    }
  });

  /**
   * Evento ao alterar liga
   */
  ligaSelect.addEventListener('change', () => {
    anoSelect.innerHTML = '<option>🔄 Carregando anos...</option>';
    anoSelect.disabled = true;

    if (usarMock) {
      const ligaNome = ligaSelect.options[ligaSelect.selectedIndex]?.textContent || '';
      const anos = wpfpMock.temporadas[ligaNome] || [];
      setTimeout(() => {
        anoSelect.innerHTML = '<option value="" disabled selected>-- Selecione o ano --</option>';
        anos.sort((a, b) => b - a).forEach(ano => {
          const opt = document.createElement('option');
          opt.value = ano;
          opt.textContent = ano;
          anoSelect.appendChild(opt);
        });
        anoSelect.disabled = false;

        if (wpfpData.selectedSeason) {
          anoSelect.value = wpfpData.selectedSeason;
          anoSelect.dispatchEvent(new Event('change'));
        }

        anoSelect.addEventListener('change', () => {
          carregarCamposSelecionadosDoPool(); // ← 💡 Gatilho para carregar jogos
        });
      }, 200);
    } else {
      const selected = ligaSelect.selectedOptions[0];
      const anos = JSON.parse(selected.dataset.years || '[]');
      setTimeout(() => {
        anoSelect.innerHTML = '<option value="" disabled selected>-- Selecione o ano --</option>';
        anos.sort((a, b) => b - a).forEach(ano => {
          const opt = document.createElement('option');
          opt.value = ano;
          opt.textContent = ano;
          anoSelect.appendChild(opt);
        });
        anoSelect.disabled = false;

        if (wpfpData.selectedSeason) {
          anoSelect.value = wpfpData.selectedSeason;
          anoSelect.dispatchEvent(new Event('change'));
        }

        anoSelect.addEventListener('change', () => {
          carregarCamposSelecionadosDoPool(); // ← 💡 Gatilho para carregar jogos
        });

      }, 200);
    }
  });

  // Eventos auxiliares
  document.getElementById('title')?.addEventListener('input', validarCamposObrigatorios);
  document.getElementById('wpfp_pontos_por_cota')?.addEventListener('input', () => {
    atualizarCalculosPremiacao();
    validarCamposObrigatorios();
  });
  document.getElementById('wpfp_cotas_max')?.addEventListener('input', () => {
    atualizarCalculosPremiacao();
    validarCamposObrigatorios();
  });

  /**
   * Inicialização: decide entre mock ou API real
   */
  setTimeout(() => {
    if (paisSelect) {
      if (usarMock) {
        console.log('⚙️ Usando MOCK para países/ligas/temporadas');
        carregarPaisesMock();
      } else {
        carregarPaises();
      }
    } else {
      console.warn("⚠️ Select de país não encontrado ao carregar página.");
    }
  }, 300);
});





// Funções auxiliares
function atualizarContador() {
    const span = document.getElementById('contador-jogos');
    if (span) span.textContent = jogosSelecionados.length;
}

function validarCamposObrigatorios() {
    const erros = [];
    const tituloInput = document.getElementById('title');
    const titulo = tituloInput?.value?.trim() || '';
    const pais = document.getElementById('wpfp_country')?.value;
    const liga = document.getElementById('wpfp_liga_brasileira')?.value;
    const ano = document.getElementById('wpfp_ano_temporada')?.value;
    const pontos = document.getElementById('wpfp_pontos_por_cota')?.value;
    const cotas = document.getElementById('wpfp_cotas_max')?.value;

    if (!titulo) erros.push('🟥 Título do pool');
    if (!pais) erros.push('🟥 País');
    if (!liga) erros.push('🟥 Liga');
    if (!ano) erros.push('🟥 Ano da temporada');
    if (!pontos) erros.push('🟥 Valor da cota');
    if (!cotas) erros.push('🟥 Quantidade de cotas');
    if (jogosSelecionados.length < 10) erros.push(`🟥 Jogos selecionados: ${jogosSelecionados.length}/10`);

    const salvarBtn = document.getElementById('wpfp_salvar');
    const aviso = document.getElementById('wpfp-form-validator');

    if (erros.length > 0) {
        salvarBtn.disabled = true;
        aviso.innerHTML = `<strong>⚠️ Campos obrigatórios faltando:</strong><br>${erros.join('<br>')}`;
    } else {
        salvarBtn.disabled = false;
        aviso.textContent = '';
    }
}

function atualizarCalculosPremiacao() {
    const cotas = parseInt(document.getElementById('wpfp_cotas_max')?.value || 0);
    const pontosPorCota = parseInt(document.getElementById('wpfp_pontos_por_cota')?.value || 0);
    const totalJogos = jogosSelecionados.length;

    const canceladosApi = jogosDisponiveis
        .filter(j => j.fixture.status.short === 'CANC')
        .map(j => j.fixture.id);

    const todosCancelados = Array.from(new Set([...canceladosApi, ...jogosCancelados]));
    const cancelados = todosCancelados.length;
    const jogosValidos = totalJogos - cancelados;

    // Atualiza número de cancelados (visualmente)
    const canceladosSpan = document.getElementById('qtd_jogos_cancelados');
    if (canceladosSpan) {
        canceladosSpan.textContent = cancelados;
    }

    if (cotas && pontosPorCota) {
        const totalPontos = jogosValidos * 5;
        const acumuladoPool = (cotas * pontosPorCota) * 0.75;
        
        const pontosCasa = (cotas * pontosPorCota) * 0.25;

        const premio1 = acumuladoPool * 0.30;
        const premio1Extra = (cotas * pontosPorCota) * 0.70;
        const premio2 = acumuladoPool * 0.30;

        const status = (cotas > 0 && totalJogos >= 10) ? 'Disponível' : 'Esgotado';

        let premioExtra = (cotas * pontosPorCota) * 0.03; // 3% do total

            // Buscar acumulado anterior (em breve será dinâmico do BD)
            const acumuladoAnterior = parseFloat(localStorage.getItem(`wpfp_acumulado_${wpfpData.poolId}`) || '0');

            premioExtra += acumuladoAnterior;


        document.getElementById('pontuacao_total_jogador').textContent = `${totalPontos} pontos`;
        document.getElementById('pontuacao_pool').textContent = `R$ ${acumuladoPool.toFixed(2)}`;
        document.getElementById('premio_extra').textContent = `R$ ${premioExtra.toFixed(2)}`;
        document.getElementById('pontuacao_casa').textContent = `R$ ${pontosCasa.toFixed(2)}`;
        document.getElementById('premio_primeiro').textContent = `R$ ${premio1.toFixed(2)}`;
        document.getElementById('premio_primeiro_extra').textContent = `R$ ${premio1Extra.toFixed(2)}`;
        document.getElementById('premio_segundo').textContent = `R$ ${premio2.toFixed(2)}`;
        document.getElementById('status_pool').textContent = status;
    }

    // Lista textual de cancelados
    const canceladosEl = document.getElementById('lista_cancelados');
    if (canceladosEl) {
        if (todosCancelados.length > 0) {
            canceladosEl.innerHTML = `<strong>⚠️ Jogos cancelados:</strong> ${todosCancelados.join(', ')}`;
            canceladosEl.style.display = 'block';
        } else {
            canceladosEl.style.display = 'none';
        }
    }
}


// Exportação JSON/CSV
document.getElementById('wpfp_export_json')?.addEventListener('click', () => {
    const dados = coletarDadosDoPool();
    const blob = new Blob([JSON.stringify(dados, null, 2)], { type: 'application/json' });
    baixarArquivo(blob, 'pool-export.json');
});

document.getElementById('wpfp_export_csv')?.addEventListener('click', () => {
    const dados = coletarDadosDoPool();
    const linhas = [['ID', 'Data', 'Time Casa', 'Time Visitante', 'Estádio', 'Cidade', 'Status', 'Cancelado']];
    dados.jogos.forEach(j => {
        linhas.push([
            j.id, j.data, j.time_casa, j.time_visitante,
            j.estadio, j.cidade, j.status, j.cancelado ? 'SIM' : 'NÃO'
        ]);
    });
    const csv = linhas.map(l => l.join(';')).join('\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    baixarArquivo(blob, 'pool-export.csv');
});

function coletarDadosDoPool() {
    const titulo = document.getElementById('title')?.value;
    const cotas = document.getElementById('wpfp_cotas_max')?.value;
    const pontos = document.getElementById('wpfp_pontos_por_cota')?.value;

    const jogos = jogosSelecionados.map(id => {
        const jogo = jogosDisponiveis.find(j => j.fixture.id === id);
        if (!jogo) return null;
        const { fixture, teams } = jogo;
        return {
            id: fixture.id,
            data: new Date(fixture.date).toLocaleString('pt-BR'),
            time_casa: teams.home.name,
            time_visitante: teams.away.name,
            estadio: fixture.venue.name || '',
            cidade: fixture.venue.city || '',
            status: fixture.status.short,
            cancelado: jogosCancelados.includes(fixture.id)
        };
    }).filter(Boolean);

    return {
        titulo,
        pontos_por_cota: pontos,
        cotas,
        jogos_cancelados: jogosCancelados,
        total_jogos: jogos.length,
        jogos
    };
}

function baixarArquivo(blob, nome) {
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = nome;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

function atualizarCamposHiddenDoForm() {
    const form = document.getElementById('post');

    // Limpar existentes
    form.querySelectorAll('input[name^="wpfp_"]').forEach(e => {
        if (e.type === 'hidden') e.remove();
    });

    // Jogos selecionados
    jogosSelecionados.forEach(id => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'wpfp_selected_games[]';
        input.value = id;
        form.appendChild(input);
    });

    // Jogos cancelados
    jogosCancelados.forEach(id => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'wpfp_jogos_cancelados[]';
        input.value = id;
        form.appendChild(input);
    });

    // ➕ Premiações salvas
    const premios = {
        wpfp_pontuacao_total: document.getElementById('pontuacao_total_jogador')?.textContent || '',
        wpfp_pontuacao_pool: document.getElementById('pontuacao_pool')?.textContent || '',
        wpfp_pontuacao_casa: document.getElementById('pontuacao_casa')?.textContent || '',
        wpfp_premio_extra: document.getElementById('premio_extra')?.textContent || '',
        wpfp_premio_primeiro: document.getElementById('premio_primeiro')?.textContent || '',
        wpfp_premio_primeiro_extra: document.getElementById('premio_primeiro_extra')?.textContent || '',
        wpfp_premio_segundo: document.getElementById('premio_segundo')?.textContent || ''
    };

    for (const [key, val] of Object.entries(premios)) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = key;
        input.value = val.replace(/[^\d,.-]/g, '').replace(',', '.'); // remove símbolos e converte vírgula
        form.appendChild(input);
    }
}



document.getElementById('wpfp_salvar')?.addEventListener('click', (e) => {
    e.preventDefault();
    window.onbeforeunload = null;

    const form = document.getElementById('post');
    if (form) {
        atualizarCamposHiddenDoForm(); // <- garante envio correto dos campos ocultos
        form.submit();

        // ✅ Redirecionar após pequeno delay para permitir submit completo
         const baseUrl = window.location.origin + window.location.pathname.split('/wp-admin')[0];
        setTimeout(() => {
            window.location.href = window.location.origin + '/bolao/wp-admin/edit.php?post_type=wpfp_pool';

        }, 1200);
    }
});





