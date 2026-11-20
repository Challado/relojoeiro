-- Relógios 2, migração v66: a vistoria periódica. Um relógio pode ficar com algo errado (a bateria no fim, a pilha vazando, o
-- vidro trincado, a pulseira soltando) sem ninguém ver, se ninguém olha para ele. A Configuração ganha "Vistoria: a cada
-- quantos dias conferir cada relógio" (vistoria_dias; 0 desliga, e vem desligada); ligada, cada relógio ativo ganha o aviso
-- Vistoria, que vence esses dias depois da última vistoria (sem nenhuma, da data da compra; sem ela, do dia do cadastro) e se
-- resolve com o lançamento Vistoria (o botão em "Hoje é dia de" ou a página Vistorias, várias de uma vez). Um serviço ou o
-- fim de uma manutenção só conta como vistoria se você marcar.
-- Tudo isso é cadastro: o tipo de lançamento Vistoria e o aviso Vistoria (modelo.json) entram em cada árvore que já existe,
-- no idioma da coleção, pelo passo em PHP (migracao_v66_dados), que grava a marca de aplicada no fim.
SET NAMES utf8mb4;
