# [DESCONTINUADO] Plugin Oficial Efi by Gerencianet para Wordpress/Woocommerce
# <center>⚠️ ATENÇÃO ⚠️</center>
[![banner](https://s3.amazonaws.com/gerencianet-pub-prod-1/printscreen/2023/12/05/joao.oliveira/Novo%20Projeto%20%284%29.png)](https://github.com/efipay/Plugin-Wordpress-Efi)

## Descrição 

Este é o Plugin Oficial fornecido pela [Efí Bank](https://sejaefi.com.br/) para WooCommerce. Com ele, o proprietário da loja pode optar por receber pagamentos por Boleto Bancário, Cartão de Crédito, Pix ou Open Finance. Todo processo é realizado por meio do checkout transparente. Com isso, o comprador não precisa sair do site da loja para efetuar o pagamento.

Caso você tenha alguma dúvida ou sugestão, entre em contato conosco pelo site [Clicando AQUI](https://sejaefi.com.br/fale-conosco).

## Requisitos
* Versão do PHP: 7.x ou superior
* Versão do WooCommerce: 5.x ou superior
* Versão do WordPress: 6.x ou superior

## Instalação automática 

1. Acesse o link em sua loja "Plugins" -> "Adicionar novo" -> No campo de busca, pesquise por "Efí Bank".
2. Clique em "Instalar agora".
4. Após a instalação, clique em "Ativar o Plugin".
5. Configure o plugin em "WooCommerce" > "Configurações" > "Pagamentos"  e comece a receber pagamentos!


## Configuração 

1. Ative o plugin.

2. Configure as credenciais de sua Aplicação. Para criar uma nova Aplicação, entre em sua conta Efí Bank, acesse o menu "API" e clique em "Aplicações" -> "Nova aplicação". Libere os escopos desejados e então insira as credenciais Client ID e Client Secret de produção e homologação nos respectivos campos de configuração do plugin.

3. Configure as opções de pagamento que deseja receber: Boleto, Cartão de Crédito, Open Finance e/ou Pix.

4. Caso utilize a opção de Cartão de Crédito:
   * Insira o Identificador de Conta de sua conta Efí Bank. 
   `Para encontrar o Identificador, entre em sua conta, acesse o menu "API" e clique em "Introdução". Na lateral direita haverá um botão chamado "Identificador de conta" basta clicar e o código identificador será exibido`

5. Caso utilize a opção de Pix:
   * Insira sua Chave Pix cadastrada em sua conta Efí.
   * Insira o seu certificado (arquivo .p12).
   * Marque o campo "Validar mTLS" caso deseje utilizar a validação mTLS em seu servidor.

6. Caso utilize a opção de Open Finance:
   * Insira seu CPF/CNPJ (APENAS NÚMEROS)
   * Nome completo
   * Número da conta Efí (Com dígito e SEM TRAÇO)

7. Recomendamos que antes de disponibilizar os pagamentos, o lojista realize testes de cobrança com o sandbox(ambiente de testes) ativado para verificar se o procedimento de pagamento está acontecendo conforme esperado.

## **Documentação Adicional**

A documentação completa com todos os endpoints e detalhes das APIs está disponível em https://dev.efipay.com.br/docs/modulos/WordPress.

Se você ainda não tem uma conta digital Efí Bank, [abra a sua agora](https://sejaefi.com.br)!

## **Comunidade no Discord**

<a href="https://comunidade.sejaefi.com.br/"><img src="https://efipay.github.io/comunidade-discord-efi/assets/img/thumb-repository.png"></a>

Se você tem a necessidade de integrar seu sistema ou aplicação a uma API completa de pagamentos, desejos de trocar experiências e compartilhar seu conhecimento, conecte-se à [comunidade da Efí no Discord](https://comunidade.sejaefi.com.br/).