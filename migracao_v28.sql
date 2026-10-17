-- Relógios 2, migração v28: o fuso horário de cada usuário e de cada coleção, e o relógio da Hora certa.
-- 1. usuario.fuso: o fuso horário da pessoa (Minha conta, "Fuso horário"; vazio: o da coleção, senão o do sistema). O pedido
--    dela roda nesse fuso: as datas com hora que a API mostra e recebe são nele; no banco, tudo continua no fuso do sistema
--    (o FUSO do config.php): a camada do banco converte ao ler e ao gravar.
-- 2. A Configuração de cada coleção ganha fuso (o fuso da coleção: o de quem não escolheu o seu, e o do cron, das mensagens
--    e das agendas dela; vazio: o do sistema) e hora_relogio (o relógio da página Hora certa: analogico, digital, anadigi ou
--    ambos; vazio: ambos). As duas chaves nascem vazias (nada a gravar: a configuração que falta vale vazio).
SET NAMES utf8mb4;

ALTER TABLE usuario ADD COLUMN fuso VARCHAR(60) NULL AFTER idioma;
