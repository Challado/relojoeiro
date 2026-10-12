-- Relógios 2, migração v21: os dias livres do plano. Um dia livre não tem relógio e o sistema não sorteia nada para ele (nem o
-- sorteio, nem a escala): fica para você escolher à mão (o "trocar por…" ou os relógios de um período). Vem do "Deixar o
-- período livre" da página Plano (rodizio, acao=periodo_livre); escolher um relógio para o dia, ou sortear de novo o período,
-- tira o dia daqui.
SET NAMES utf8mb4;

CREATE TABLE plano_livre (
    data        DATE PRIMARY KEY,
    criado      DATETIME NOT NULL
) ENGINE=InnoDB;
