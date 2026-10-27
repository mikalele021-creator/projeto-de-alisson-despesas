# Gerenciador de Despesas em PHP

Aplicação simples em PHP + SQLite, com páginas separadas para Visão geral, Despesas, Relatórios mensais e Salário mensal.

## Recursos
- Dashboard com salário, gastos do mês, saldo estimado e gasto acumulado.
- Cadastro e exclusão de despesas, com categorias.
- Página separada para consultar despesas por mês e categoria.
- Página de relatórios com até 24 meses de histórico e acesso a cada período.
- Cadastro de salário independente para cada mês.
- Gráfico dos últimos seis meses até o mês selecionado.
- Banco SQLite criado automaticamente; se já existir `despesas.sqlite`, os registros anteriores de despesas são preservados.
- Consultas preparadas, escape de HTML e token CSRF nos formulários.

## Requisitos
- PHP 8.0+ com `PDO_SQLite` e `mbstring` habilitados.
- Permissão de escrita na pasta do projeto.

## Executar no Windows
1. Extraia o ZIP.
2. Abra o terminal na pasta que contém `index.php`.
3. Execute `php -S localhost:8000`.
4. Acesse `http://localhost:8000`.

O arquivo `despesas.sqlite` é criado automaticamente. Preserve-o ao atualizar o código para manter os registros. O servidor embutido do PHP é para desenvolvimento local, não para uso público em produção.
