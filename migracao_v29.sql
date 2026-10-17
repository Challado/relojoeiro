-- Relógios 2, migração v29: o fuso horário de cada destino e de cada agenda.
-- As horas no texto das mensagens (Telegram, e-mail, Web Push) e nas descrições dos eventos das agendas saem no fuso do
-- destino ou da agenda (Destinos e Agendas, "Fuso horário"; vazio: o da coleção), como já saem no idioma deles. O instante
-- do evento no calendário já era certo (o Google e o Outlook recebem o fuso, o ICS vai em UTC): muda só o texto.
SET NAMES utf8mb4;

ALTER TABLE destino ADD COLUMN fuso VARCHAR(60) NULL AFTER idioma;
ALTER TABLE agenda ADD COLUMN fuso VARCHAR(60) NULL AFTER idioma;
