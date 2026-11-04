-- Relógios 2, migração v58: as opções da coleção em três níveis (sistema → usuário → coleção).
-- Cada opção da Configuração (os horários, os limites, o gasto da bateria, as telas...) passa a ter um padrão do sistema (a tabela
-- sistema, padrao_<chave>, que o master escolhe), um padrão do usuário para as coleções de que é dono (esta tabela, uma linha
-- por opção que ele escolheu) e a escolha da coleção (o config; vazio herda). O mais específico prevalece; as coleções que já
-- tinham o valor gravado seguem com ele.
SET NAMES utf8mb4;

CREATE TABLE usuario_opcao (
    usuario_id  INT NOT NULL,
    chave       VARCHAR(40) NOT NULL,
    valor       VARCHAR(100) NOT NULL,
    PRIMARY KEY (usuario_id, chave),
    FOREIGN KEY (usuario_id) REFERENCES usuario(id) ON DELETE CASCADE,
    CONSTRAINT ck_usuario_opcao_chave CHECK (TRIM(chave) <> '')
) ENGINE=InnoDB;

-- O valor que a coleção tem igual ao padrão de fábrica passa a herdar (vazio): efeito nenhum agora, e daí em diante o padrão do
-- usuário e o do sistema valem nela. O valor diferente do padrão é escolha dela e fica.
UPDATE config SET valor = '' WHERE chave = 'horario_manha' AND valor = '06:30';
UPDATE config SET valor = '' WHERE chave = 'horario_noite' AND valor = '20:00';
UPDATE config SET valor = '' WHERE chave = 'uso_inicio' AND valor = '07:00';
UPDATE config SET valor = '' WHERE chave = 'pulso_auto_inicio' AND valor = '1';
UPDATE config SET valor = '' WHERE chave = 'uso_fim' AND valor = '22:00';
UPDATE config SET valor = '' WHERE chave = 'pulso_auto_fim' AND valor = '1';
UPDATE config SET valor = '' WHERE chave = 'sol_auto_fim' AND valor = '1';
UPDATE config SET valor = '' WHERE chave = 'semana_inicio' AND valor = 'segunda';
UPDATE config SET valor = '' WHERE chave = 'escala_inicio' AND valor = 'amanha';
UPDATE config SET valor = '' WHERE chave = 'escala_periodo' AND valor = 'corridos';
UPDATE config SET valor = '' WHERE chave = 'semana_ultimo_dia' AND valor = 'dia_seguinte';
UPDATE config SET valor = '' WHERE chave = 'trocar_hoje' AND valor = '0';
UPDATE config SET valor = '' WHERE chave = 'max_sem_uso' AND valor = '21';
UPDATE config SET valor = '' WHERE chave = 'sorteio_casas' AND valor = '2';
UPDATE config SET valor = '' WHERE chave = 'carga_limiar' AND valor = '20';
UPDATE config SET valor = '' WHERE chave = 'sol_limiar' AND valor = '70';
UPDATE config SET valor = '' WHERE chave = 'previsao_limite' AND valor = '20';
UPDATE config SET valor = '' WHERE chave = 'medicao_base' AND valor = 'dias';
UPDATE config SET valor = '' WHERE chave = 'medicao_janela_dias' AND valor = '90';
UPDATE config SET valor = '' WHERE chave = 'medicao_janela_leituras' AND valor = '20';
UPDATE config SET valor = '' WHERE chave = 'medicao_meia_vida_dias' AND valor = '0';
UPDATE config SET valor = '' WHERE chave = 'encostado_dias' AND valor = '30';
UPDATE config SET valor = '' WHERE chave = 'painel_modo' AND valor = 'lado';
UPDATE config SET valor = '' WHERE chave = 'destaque_hoje' AND valor = 'dia';
UPDATE config SET valor = '' WHERE chave = 'calibre_campos' AND valor = 'movimento';
UPDATE config SET valor = '' WHERE chave = 'relojinho_modo' AND valor = 'hora';
UPDATE config SET valor = '' WHERE chave = 'hora_relogio' AND valor = 'ambos';
UPDATE config SET valor = '' WHERE chave = 'proximos_por_pagina' AND valor = '7';
UPDATE config SET valor = '' WHERE chave = 'plano_por_pagina' AND valor = '31';
UPDATE config SET valor = '' WHERE chave = 'atualizar_segundos' AND valor = '60';
