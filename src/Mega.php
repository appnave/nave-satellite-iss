<?php

namespace Nave\IssSatellite;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use Yajra\Oci8\Query\OracleBuilder;

class Mega
{
    private static array $connectionData;

    private static string $connectionName;

    /**
     * NÃO IMPLEMENTADOS
     * getVizinhosByCodUnidade() - Não é Mega, manipula dados do banco do SYS
     * contratoByCtoInCodigo() - Alias de buscaDadosUnidade
     * ecommerce() - Utiliza banco SYS
     * getTelefonesByAgnCodigo() - Utiliza ORM rudimentar do Mega que fizeram, ver se vai importar
     * insertTelefones() - Utiliza ORM rudimentar do Mega que fizeram, ver se vai importar
     * sincronizaPermutantes() - Rotina de buscar no Mega por permutantes e inserir no SYS. Utiliza uma query Mega no início mas o restante é SYS
     */
    public static function setConnectionData(array $connectionData): void
    {
        self::$connectionName = "iss-satellite-{$connectionData['connection_name_prefix']}";
        self::$connectionData = [
            'host' => $connectionData['host'],
            'port' => $connectionData['port'],
            'database' => $connectionData['database'],
            'service_name' => $connectionData['database'],
            'username' => $connectionData['username'],
            'password' => $connectionData['password'],
        ];
    }

    protected static function connectionConfig(): void
    {
        config([
            'database.connections.'.self::$connectionName => array_merge(
                config('iss-satellite.oracle.db'),
                self::$connectionData
            ),
        ]);
    }

    public static function connection(): \Illuminate\Database\Connection
    {
        self::connectionConfig();

        DB::purge(self::$connectionName);

        return DB::connection(self::$connectionName);
    }

    /**
     * Retorna a query das vendas permutantes
     */
    public static function getPermutationSalesQuery(): Builder
    {
        return self::connection()
            ->table('bild.vw_bld_ono_parc_cli_sys_api')
            ->whereNotIn('status_cto', ['D', 'C', 'T'])
            ->orderBy('cod_contrato', 'desc');
    }

    /**
     * Retorna todo o fluxo de pagamento de uma data base, CPF/CNPJ e empreendimento
     *
     * $data deve conter os seguintes campos:
     * - data_base: Data base no formato 'd/m/Y' (opcional, se não for passado, será utilizado a data atual)
     * - dt_movimento: Data do movimento no formato 'd/m/Y' (opcional, se não for passado, será considerado '0')
     * - est_in_codigo: Código do empreendimento (opcional, se não for passado, será considerado '0')
     * - document: CPF ou CNPJ do cliente (opcional, se não for passado, será considerado '0') passar ele formatado com máscara, ex: 123.456.789-00 ou 12.345.678/0001-00
     */
    public static function getFinancialStatementBaseDate(array $data): array
    {
        if (empty($data)) {
            return [];
        }

        $data_base = "to_date(:data_base, 'dd/mm/yyyy') - 1";

        $query = "
            select vbo.org_in_codigo,
                vbo.cto_in_codigo,
                vbo.cto_ch_status,
                vbo.codigo_exporta,
                vbo.documento,
                vbo.cto_dt_cadastro,
                vbo.cto_re_valorcontrato,
                vbo.cto_re_valorcontrato_ori,
                vbo.par_ch_parcela,
                vbo.par_ch_receita,
                vbo.par_in_codigo,
                vbo.par_re_valororiginal,
                vbo.par_dt_vencimento,
                vbo.par_dt_baixa,
                vbo.sequencia,
                vbo.par_ch_status,
                vbo.par_dt_movimento
            from bild.vw_bld_ono_parcela_sys_api vbo,
                (
                    select distinct
                        cpa.org_tab_in_codigo_new  as org_tab_in_codigo,
                        cpa.org_pad_in_codigo_new  as org_pad_in_codigo,
                        cpa.org_in_codigo_new      as org_in_codigo,
                        cpa.org_tau_st_codigo_new  as org_tau_st_codigo,
                        cpa.cto_in_codigo_new      as cto_in_codigo,
                        cpa.par_in_codigo_new      as par_in_codigo,
                        'S'                        as parcela_alterada
                    from
                        bild.a#car_parcela   cpa,
                        bild.adt_ocorrencia  aoc
                    where cpa.ado_in_ocorrencia = aoc.ado_in_ocorrencia
                    and aoc.ado_ch_operacao       in ('I','U')
                    and trunc(aoc.ado_dt_inclusao) = " . $data_base . "
                )  qry_par
            where vbo.org_tab_in_codigo = qry_par.org_tab_in_codigo (+)
            and vbo.org_pad_in_codigo   = qry_par.org_pad_in_codigo (+)
            and vbo.org_in_codigo       = qry_par.org_in_codigo     (+)
            and vbo.org_tau_st_codigo   = qry_par.org_tau_st_codigo (+)
            and vbo.cto_in_codigo       = qry_par.cto_in_codigo     (+)
            and vbo.par_in_codigo       = qry_par.par_in_codigo     (+)
        ";

        if (array_key_exists('data_base', $data)) {
            $data_base = Carbon::parse($data['data_base'])->format('d/m/Y');
        } else {
            $data_base = date('Y-m-d');
            $data_base = Carbon::parse($data_base)->format('d/m/Y');
        }

        $data['data_base'] = $data_base;

        if (array_key_exists('dt_movimento', $data)) {
            $data['dt_movimento'] = Carbon::parse($data['dt_movimento'])->format('d/m/Y');
            $query .= "and vbo.par_dt_movimento = to_date(:dt_movimento, 'dd/mm/yyyy') - 1 ";
        }

        if (array_key_exists('est_in_codigo', $data) && $data['est_in_codigo'] != '0') {
            $query .= 'and vbo.codigo_exporta = :est_in_codigo ';
        }

        if (array_key_exists('document', $data) && ($data['document'] != '0')) {
            $query .= 'and documento = :document ';
        }

        if (
            (array_key_exists('document', $data) && ($data['document'] == '0'))
            && (array_key_exists('est_in_codigo', $data) && ($data['est_in_codigo'] == '0'))
        ) {
            $query .= " and (nvl(qry_par.parcela_alterada, 'N') = 'S' or trunc(vbo.cto_dt_status) = " . $data_base . ')';
        }

        return self::connection()->select($query, $data);
    }

    /**
     * NÃO TESTADO
     * Função que atualiza os dados do cliente
     *
     * @param  array  $data
     */
    public static function atualizaDadosCliente($data): int
    {
        $query = ' update bild.glo_agentes t
                    set t.agn_st_nome        = :agn_st_nome,
                        t.agn_st_logradouro  = :agn_st_logradouro,
                        t.agn_st_numero      = :agn_st_numero,
                        t.agn_st_complemento = :agn_st_complemento,
                        t.agn_st_bairro      = :agn_st_bairro,
                        t.agn_st_municipio   = :agn_st_municipio,
                        t.agn_st_cep         = :agn_st_cep,
                        t.agn_st_email       = :agn_st_email
                where t.agn_in_codigo     = :agn_in_codigo
                    and t.agn_pad_in_codigo = :agn_pad_in_codigo
                    and t.agn_tab_in_codigo = :agn_tab_in_codigo';

        return self::connection()->update($query, $data);
    }

    public static function getEstadosCivis(): array
    {
        $query = 'select * from bild.glo_estadocivil';

        return self::connection()->select($query);
    }

    /**
     * Função que pega os dados do cliente pelo cpf/cnpj
     */
    public static function clientes($data): array
    {
        $query = "select CTO_IN_CODIGO,
                    AGN_TAB_IN_CODIGO,
                    AGN_PAD_IN_CODIGO,
                    AGN_IN_CODIGO,
                    AGN_ST_NOME,
                    AGN_CH_ESTCIVIL,
                    AGN_CH_ESTCIVIL_EXTENSO,
                    COD_REGIME_CASAMENTO,
                    DESCR_REGIME_CASAMENTO,
                    AGN_DT_NASCIMENTO,
                    AGN_ST_CARGOPROFISS,
                    AGN_ST_EMAIL,
                    TPL_ST_SIGLA,
                    AGN_ST_LOGRADOURO,
                    AGN_ST_NUMERO,
                    AGN_ST_CEP,
                    UF_ST_SIGLA,
                    AGN_ST_MUNICIPIO,
                    AGN_ST_BAIRRO,
                    AGN_ST_COMPLEMENTO,
                    AGN_ST_CPF,
                    RG,
                    CNPJ,
                    AGN_ST_CONJUGE,
                    AGN_ST_CPF_CONJUGE,
                    AGN_ST_RG_CONJUGE,
                    AGN_ST_NACIONALIDADE
            from  bild.vw_bld_bca_cto_cli_api
            WHERE AGN_ST_CPF = ':document'
                    OR CNPJ = ':document'
                    AND rownum = 1";

        return self::connection()->select($query, $data);
    }

    /**
     * Função que pega os dados do cliente pelo cto_in_codigo
     */
    public static function clienteByCtoInCodigo($data): array
    {
        $query = "select CTO_IN_CODIGO,
                    AGN_TAB_IN_CODIGO,
                    AGN_PAD_IN_CODIGO,
                    AGN_IN_CODIGO,
                    AGN_ST_NOME,
                    AGN_CH_ESTCIVIL,
                    AGN_CH_ESTCIVIL_EXTENSO,
                    COD_REGIME_CASAMENTO,
                    DESCR_REGIME_CASAMENTO,
                    AGN_DT_NASCIMENTO,
                    AGN_ST_CARGOPROFISS,
                    AGN_ST_EMAIL,
                    TPL_ST_SIGLA,
                    AGN_ST_LOGRADOURO,
                    AGN_ST_NUMERO,
                    AGN_ST_CEP,
                    UF_ST_SIGLA,
                    AGN_ST_MUNICIPIO,
                    AGN_ST_BAIRRO,
                    AGN_ST_COMPLEMENTO,
                    AGN_ST_CPF,
                    RG,
                    CNPJ,
                    AGN_ST_CONJUGE,
                    AGN_ST_CPF_CONJUGE,
                    AGN_ST_RG_CONJUGE,
                    AGN_ST_NACIONALIDADE
            from  bild.vw_bld_bca_cto_cli_api
            WHERE CTO_IN_CODIGO = ':cto_in_codigo'
                    AND rownum = 1";

        return self::connection()->select($query, $data);
    }

    /**
     * Função que pega os dados do cliente pelo cpf/cnpj ou pelo nome
     */
    public static function clientesSac($data): array
    {
        $query = 'select CTO_IN_CODIGO,
                    AGN_TAB_IN_CODIGO,
                    AGN_PAD_IN_CODIGO,
                    AGN_IN_CODIGO,
                    AGN_ST_NOME,
                    AGN_CH_ESTCIVIL,
                    AGN_CH_ESTCIVIL_EXTENSO,
                    COD_REGIME_CASAMENTO,
                    DESCR_REGIME_CASAMENTO,
                    AGN_DT_NASCIMENTO,
                    AGN_ST_CARGOPROFISS,
                    AGN_ST_EMAIL,
                    TPL_ST_SIGLA,
                    AGN_ST_LOGRADOURO,
                    AGN_ST_NUMERO,
                    AGN_ST_CEP,
                    UF_ST_SIGLA,
                    AGN_ST_MUNICIPIO,
                    AGN_ST_BAIRRO,
                    AGN_ST_COMPLEMENTO,
                    AGN_ST_CPF,
                    RG,
                    CNPJ,
                    AGN_ST_CONJUGE,
                    AGN_ST_CPF_CONJUGE,
                    AGN_ST_RG_CONJUGE,
                    AGN_ST_NACIONALIDADE
            from  bild.vw_bld_bca_cto_cli_api ';
        if (! empty($data['document']) && empty($data['agn_st_nome'])) {
            $query .= "WHERE AGN_ST_CPF = ':document'
                        OR CNPJ = ':document'";
        } elseif (empty($data['document']) && ! empty($data['agn_st_nome'])) {
            $query .= "WHERE AGN_ST_NOME like ':agn_st_nome'";
        } else {
            $query .= "WHERE AGN_ST_CPF = ':document'
                        OR CNPJ = ':document'
                        AND AGN_ST_NOME like ':agn_st_nome'";
        }

        $customers = self::connection()->select($query, $data);

        foreach ($customers as $customer) {
            $customer->unidade = self::getUnitsByCtoInCodigo($customer->cto_in_codigo);
        }

        return $customers;
    }

    /**
     * NÃO TESTADO
     */
    public static function getEntregaDeChaveByCtoInCodigo($data): array
    {
        $query = 'select * from bild.vw_bld_ono_contr_ocor_sys_api
            where cod_contrato = :cto_in_codigo';

        return self::connection()->select($query, $data);
    }

    /**
     * NÃO TESTADO
     */
    public static function buscaDadosUnidade($data): array
    {
        $query = 'select *
                    from bild.vw_bld_ono_contrato_sys_api
                where cto_in_codigo = :cto_in_codigo';

        return self::connection()->select($query, $data);
    }

    /**
     * NÃO TESTADO
     */
    public static function contratos($data): array
    {
        $query = 'select *
                from bild.vw_bld_ono_contrato_sys_api
            where agn_in_codigo = :agn_in_codigo';

        return self::connection()->select($query, $data);
    }

    /**
     * NÃO TESTADO
     * Traz os status do boleto de sinal
     */
    public static function statusBoletoSinal($data): array
    {
        $query = 'select t.*
                from bild.alx_viw_bldbolrapido t
                where t.agn_in_codigo = :agn_in_codigo';

        return self::connection()->select($query, $data);
    }

    /**
     * NÃO TESTADO
     * Traz o extrato do cliente, mesma table de extratoFinanceiro, porém essa não traz duplicado
     */
    public static function sacExtratoCliente($data): array
    {
        $query = 'select *
                from bild.vw_bld_ono_flu_cli_rep_sys_api
                where codigo_contrato_mega = :cto_in_codigo';

        return self::connection()->select($query, $data);
    }

    /**
     * Lista todos os empreendimentos
     */
    public static function empreendimentos(): array
    {
        $query = 'select *
                from bild.vw_bld_ono_est_emp_sys_api';

        return self::connection()->select($query);
    }

    /**
     * NÃO TESTADO
     * Mostra todo o fluxo financeiro do contrato
     */
    public static function extratoFinanceiro($data): array
    {
        $data['date'] = date('Y-m-d');
        $data['seq'] = 1;

        $query = 'begin
            bild.alx_pck_bldapp.fnc_bld_app_parcela (:organizacao
            , :cto_in_codigo
            , :sequencia
            , :data_base);
            end;';

        self::connection()->select($query, $data);

        $query = 'select t.* from bild.alx_clitmpcontratos t
                where t.org_in_codigo = :organizacao and
                t.cto_in_codigo = :cto_in_codigo and t.seq_in_codigo = :sequencia';

        return self::connection()->select($query, $data);
    }

    /**
     * NÃO TESTADO
     * Retorno a lista de boletos com linha digitavel (liberado pelo financeiro)
     */
    public static function boletos($data): array
    {
        $query = 'begin
            bild.alx_pck_bldapp.fnc_bld_app_parcela (:organizacao
            , :cto_in_codigo
            , :sequencia
            , :data_base);
            end;';
        self::connection()->select($query, $data);

        $query = 'select t.* from bild.alx_clitmpcontratos t
                where t.org_in_codigo = :org_in_codigo
                and t.cto_in_codigo = :cto_in_codigo
                and t.seq_in_codigo = :sequencia
                and t.par_st_linhadigitavel is not null';

        return self::connection()->select($query, $data);
    }

    /**
     * NÃO TESTADO
     */
    public static function usuarios($data): array
    {
        $pResult = '';

        $query = 'begin
            bild.alx_pck_bldallapisharepoint.p_busca_usuario (:pResult
                , :pCod
                , :pNome
                , :pTipo);
            end;';

        return self::connection()->select($query, $data);
    }

    /**
     * NÃO TESTADO
     */
    public static function insereUsuario($data): array
    {
        $pResult = '';

        $query = ' begin
            bild.alx_pck_bldallapisharepoint.p_insere_usuario_mega (:plogin
                , :pnome
                , :pemail
                , :pusubase
                , :pcopia_cc
                , :pcopia_proj
                , :pcopia_org
                , :pcopia_mat
                , :pcopia_grupos);
            end;';

        return self::connection()->select($query, $data);
    }

    /**
     * NÃO TESTADO
     */
    public static function spes($data): array
    {
        $pResult = '';

        $query = 'begin
            bild.alx_pck_bldallapisharepoint.p_busca_spe (:pResult
                , :pTexto
                , :pCod
                , :pTipo);
            end;';

        return self::connection()->select($query, $data);
    }

    /**
     * NÃO TESTADO
     */
    public static function spesUsuarios($data): array
    {
        $pResult = '';

        $query = 'begin
            bild.alx_pck_bldallapisharepoint.p_busca_spe_usuario (:pResult
                    , :pOperacao
                    , :pUsuario);
            end;';

        return self::connection()->select($query, $data);
    }

    /**
     * NÃO TESTADO
     */
    public static function perfilUsuario($pUsuario): array
    {
        $query = ' begin
                bild.alx_pck_bldallapisharepoint.p_busca_perfil_usuario (:pResult
                    , :pUsuario);
            end;';

        return self::connection()->select($query, $data);
    }

    /**
     * NÃO TESTADO
     */
    public static function inclusaoAlcada($data): array
    {
        $query = ' begin
                bild.alx_pck_bldallapisharepoint.p_busca_spe_inclusao_alcada (:pResult
                    , :pUsuario);
            end;';

        return self::connection()->select($query, $data);
    }

    /**
     * NÃO TESTADO
     */
    public static function alcadaUsuario($data): array
    {
        $pResult = '';

        $query = 'begin
            bild.alx_pck_bldallapisharepoint.p_busca_alcada_usuario (:pResult
                , :pUsuario);
            end;';

        return self::connection()->select($query, $data);
    }

    /**
     * NÃO TESTADO
     */
    public static function insereSpeUsuario($data): array
    {
        $query = 'begin
                    bild.alx_pck_bldallapisharepoint.p_insere_spe_usuario (:pUsuario
                    , :pOrg_tab
                    , :pOrg_pad
                    , :pFil_Cod
                    , :pOrg_tau
                    , :pPerfil);
            end;';

        return self::connection()->select($query, $data);
    }

    /**
     * NÃO TESTADO
     */
    public static function deleteSpeUsuario($data): array
    {
        $query = 'begin
            bild.alx_pck_bldallapisharepoint.p_deleta_spe_usuario (:pUsuario
                , :pFil_Cod);
            end;';

        return self::connection()->select($query, $data);
    }

    /**
     * NÃO TESTADO
     */
    public static function atualizaAlcadaUsuario($data): array
    {
        $query = 'begin
                bild.alx_pck_bldallapisharepoint.p_atualiza_alcada_usuario (:pUsuario
                    , :pOrg_tab
                    , :pOrg_pad
                    , :pOrg_cod
                    , :pOrg_tau
                    , :pFil_cod
                    , :pNivel_1
                    , :pNivel_2
                    , :pNivel_3
                    , :pEstouro);
            end;';

        return self::connection()->select($query, $data);
    }

    /**
     * NÃO TESTADO
     */
    public static function deletaAlcadaUsuario($data): array
    {
        $query = 'begin
            bild.alx_pck_bldallapisharepoint.p_deleta_alcada_usuario (:pUsuario
                , :pOrg_tab
                , :pOrg_pad
                , :pOrg_cod
                , :pOrg_tau
                , :pFil_cod);
            end;';

        return self::connection()->select($query, $data);
    }

    /**
     * NÃO TESTADO
     */
    public static function clientesPorTelefone($data): array
    {
        $query = "select * from bild.vw_bld_ono_cli_contr_sys_api
            where celular =':phone'";

        return self::connection()->select($query, $data);
    }

    /**
     * NÃO TESTADO
     */
    public static function torresPorEmpreendimento($data): array
    {
        $query = 'select * from bild.dbm_vw_estrutura e where e.emp_codigo = :realEstateDevelopmentId ';

        return self::connection()->select($query, $data);
    }

    /**
     * NÃO TESTADO
     */
    public static function torresPorUnidade($data): array
    {
        $query = 'select * from bild.dbm_vw_estrutura e
                where e.und_codigo = :unitId ';

        return self::connection()->select($query, $data);
    }

    /**
     * NÃO TESTADO
     */
    public static function buscaPedidosSuprimentos($data): array
    {
        $query = 'begin
            bild.alx_pck_bldallapisuprimentos.p_busca_pedidos (:pResult
                , :pDocumento
                , :pData_ini);
            end;';

        return self::connection()->select($query, $data);
    }

    /**
     * NÃO TESTADO
     */
    public static function buscaContratosSuprimentos($data): array
    {
        $query = 'begin
            bild.alx_pck_bldallapisuprimentos.p_busca_contratos (:pResult
                , :pDocumento
                , :pData_ini);
            end;';

        return self::connection()->select($query, $data);
    }

    /**
     * NÃO TESTADO
     */
    public static function fluxoCliente($data): array
    {
        $query = "select *
                from bild.vw_bld_ono_flu_cli_rep_sys_api
            where codigo_unidade_mega = :id_unidade
                and (cpf = ':cpf_cliente' or cnpj = ':cpf_cliente')";

        return self::connection()->select($query, $data);
    }

    /**
     * NÃO TESTADO
     */
    public static function verificaStatusNF($data): array
    {
        $query = 'begin
            bild.alx_pck_bldallapisuprimentos.p_verifica_nota (:pREtorno
                , :pFil_doc
                , :pFor_doc
                , :pNota
                , :pData_Emissao);
            end;';

        return self::connection()->select($query, $data);
    }

    /**
     * NÃO TESTADO
     */
    public static function getDadosTermo($data): array
    {
        $query = 'begin
            bild.alx_pck_bldonointegracao.prc_rec_dados_termo (:p_in_cod_exporta
                , :p_in_tipo_doc
                , :p_in_cpf_cnpj
                , :p_in_termo
                , :p_in_usuario
                , :p_in_computador
                , :p_out_result);
            end;';

        return self::connection()->select($query, $data);
    }

    /**
     * NÃO TESTADO
     */
    public static function getDespesaITBI($data): array
    {
        $query = 'begin
                bild.alx_pck_bldonointegracao.prc_rec_despesas_itbi (:p_in_cod_exporta
                    , :p_in_tipo_doc
                    , :p_in_cpf_cnpj
                    , :p_in_termo
                    , :p_in_usuario
                    , :p_in_computador
                    , :p_out_result);
            end;';

        return self::connection()->select($query, $data);
    }

    /**
     * NÃO TESTADO
     */
    public static function getFluxoAssociativo($data): array
    {
        $query = 'begin
                bild.alx_pck_bldonointegracao.prc_rec_status_fluxo_assoc (:p_in_cod_exporta
                    , :p_in_tipo_doc
                    , :p_in_cpf_cnpj
                    , :p_in_usuario
                    , :p_in_computador
                    , :p_out_result);
            end;';

        return self::connection()->select($query, $data);
    }

    /**
     * NÃO TESTADO
     */
    public static function atualizaFluxoAssociativo($data): array
    {
        $query = 'begin
            bild.alx_pck_bldonointegracao.prc_atualiz_status_fluxo_assoc (:p_in_cod_exporta
                , :p_in_tipo_doc
                , :p_in_cpf_cnpj
                , :p_in_status
                , :p_in_usuario
                , :p_in_computador
                , :p_out_result);
            end;';

        return self::connection()->select($query, $data);
    }

    /**
     * NÃO TESTADO
     */
    public static function atualizaDataBancoEscFluxoAssociativo($data): array
    {
        $query = 'begin
            bild.alx_pck_bldonointegracao.prc_atualiz_datas_fluxo_assoc (:p_in_cod_exporta
                , :p_in_tipo_doc
                , :p_in_cpf_cnpj
                , :p_in_tipo
                , :p_in_data
                , :p_in_usuario
                , :p_in_computador
                , :p_out_result);
                end;';

        return self::connection()->select($query, $data);
    }

    /**
     * NÃO TESTADO
     */
    public static function consultaRM($data): array
    {
        $query = "select *
            from bild.vw_bld_ono_req_mat_sys_api
            where filial = :cod_filial
            and trunc(dt_requisicao) between to_date(':data_inicio', 'DD-MM-YYYY')
            and to_date(':data_fim', 'DD-MM-YYYY')";

        return self::connection()->select($query, $data);
    }

    /**
     * NÃO TESTADO
     */
    public static function consultaCVV($data): array
    {
        $query = "select *
            from bild.vw_bld_ono_nota_ap_sys_api
            where dataentrada >= to_date(':data_inicio', 'DD-MM-YYYY')
            and dataentrada <= to_date(':data_fim', 'DD-MM-YYYY')
            and agn_in_codigo = :cod_filial";

        return self::connection()->select($query, $data);
    }

    /**
     * NÃO TESTADO
     */
    public static function getdataAssembleia($data): array
    {
        $query = "select est.fil_in_codigo
                , est.est_st_nome
                , est.est_dt_instcondominio
            from bild.dbm_estrutura  est
            where est.est_ch_tipoestrutura = 'E'
            and est.fil_in_codigo = :fil_in_codigo";

        return self::connection()->select($query, $data);
    }

    /**
     * NÃO TESTADO
     */
    public static function addContaBancariaCliente($data): array
    {
        $query = 'begin
            bild.alx_pck_bldbcaintegracaosys.prc_bld_bca_insere_cc_cli (
                p_documento     => :p_documento     -- CPF ou CPNJ do Cliente;
                , p_est_in_codigo => :p_est_in_codigo -- Código exporta da unidade;
                , p_ban_in_numero => :p_ban_in_numero -- Número do banco;
                , p_age_st_numero => :p_age_st_numero -- Número da agencia;
                , p_contacorr     => :p_contacorr     -- Número da conta;
                , p_tipoconta     => :p_tipoconta     -- C ou P;
                , p_titular       => :p_titular
                , p_cpftitular    => :p_cpftitular
                , p_cnpjtitular   => :p_cnpjtitular);
            end;';

        return self::connection()->select($query, $data);
    }

    /**
     * NÃO TESTADO
     */
    public static function getContaBancariaCliente($data): array
    {
        $query = 'begin
            bild.alx_pck_bldbcaintegracaosys.prc_bld_bca_rec_cc_cli (p_out_result    => :p_out_result
                , p_documento     => :p_documento -- CPF ou CPNJ do Cliente;
                , p_est_in_codigo => :p_est_in_codigo); -- Código exporta da unidade;
            end;';

        return self::connection()->select($query, $data);
    }

    public static function listarParcelasGeradasMegaFinnet(
        int $idVenda,
        ?int $propInSequencia = null,
        ?int $numParcela = null,
        bool $exibeJsonpar = false,
        bool $exibeBoletoBase64 = false,
        bool $statusGreaterThanZero = false
    ): SupportCollection {
        /*
         * 00 - Aguardando Json
         * 10 - Processado
         * 20 - Baixado
         * 30 - Integrado com o Oracle
         * 40 - Integrado com o Mega
         * 80 - Cancelado
         * 99 - Processado com Erro
         */

        return self::connection()
            ->table('BILD.CLI_PROPOSTA_SYS')
            ->select([
                'prop_in_codigo',
                'documento',
                'est_in_codigo',
                'prop_vencto',
                'vlr_parcela',
                'nossonumero',
                'valor_baixa',
                'data_baixa',
                'status',
                'prop_in_parcela',
                'ret_status',
                'ret_mensagem',
                'prop_in_sequencia',
                'vlr_prestamista',
                'agncfi_in_codigo',
                'agn_ch_boleto',
                DB::raw("TO_CHAR(data_baixa, 'YYYY-MM-DD') AS data_baixa_formato_en"),
                DB::raw("TO_CHAR(data_credito, 'YYYY-MM-DD') AS data_credito_formato_en"),
                DB::raw("TO_CHAR(prop_vencto, 'YYYY-MM-DD') AS prop_vencto_formato_en"),
            ])
            ->when($exibeBoletoBase64, function (OracleBuilder $query) {
                $query->addSelect('boletobase64');
            })
            ->when($exibeJsonpar, function (OracleBuilder $query) {
                $query->addSelect('jsonpar');
            })
            ->when($idVenda, function (OracleBuilder $query, int $idVenda) {
                $query->where('prop_in_codigo', $idVenda);
            })
            ->when($numParcela, function (OracleBuilder $query, int $numParcela) {
                $query->where('prop_in_parcela', $numParcela);
            })
            ->when($propInSequencia, function (OracleBuilder $query, int $propInSequencia) {
                $query->where('prop_in_sequencia', $propInSequencia);
            })
            ->when($statusGreaterThanZero, function (OracleBuilder $query) {
                $query->where('status', '>=', 0);
            }, function (OracleBuilder $query) {
                $query->where('status', '!=', 80);
            })
            ->orderBy('prop_in_sequencia')
            ->get();
    }

    public static function addPropostaMega(string $cpfCliente, string $codUnidade, string $codProposta, int $codTermo, string $status): void
    {
        self::connection()
            ->table('bild.ALX_CLIINTPROPOSTATERMO')
            ->insert([
                'PROP_CLIENTE'   => $cpfCliente,
                'EST_IN_CODIGO'  => $codUnidade,
                'PROP_IN_PROP'   => $codProposta,
                'TER_IN_CODIGO'  => $codTermo,
                'PROP_CH_STATUS' => $status,
                'PROP_DT_IMPORT' => now()->toDateTimeString(),
                'PROP_DT_PROCES' => now()->toDateTimeString(),
            ]);
    }

    public static function hasPropostaMega(
        string $cpfCliente,
        string $codUnidade,
        string $codProposta,
        ?int $codTermo = null,
        ?string $status = null
    ): int {
        return self::connection()
            ->table('bild.ALX_CLIINTPROPOSTATERMO')
            ->where([
                ['PROP_CLIENTE', $cpfCliente],
                ['EST_IN_CODIGO', $codUnidade],
                ['PROP_IN_PROP', $codProposta],
            ])
            ->when($codTermo, function (OracleBuilder $query) use ($codTermo) {
                $query->where('TER_IN_CODIGO', $codTermo);
            })
            ->when($status, function (OracleBuilder $query) use ($status) {
                $query->where('PROP_CH_STATUS', $status);
            })
            ->count();
    }

    public static function addParcelasMega(
        string $cpfCliente,
        string $codUnidade,
        int $numeroParcela,
        string $codProposta,
        string $tipoParcela,
        string $dataVencimento,
        string $valor,
        string $status,
        int|float $porcentagem,
        string $dataImporta
    ): void {
        self::connection()
            ->table('bild.ALX_CLIINTPROPTERMOPARC')
            ->insert([
                'PROP_CLIENTE'       => $cpfCliente,
                'EST_IN_CODIGO'      => $codUnidade,
                'PROP_IN_PARC'       => $numeroParcela,
                'PROP_IN_PROP'       => $codProposta,
                'PROP_CH_PARC'       => $tipoParcela,
                'PROP_DT_VENCTO'     => DB::raw("TO_DATE('$dataVencimento', 'DD/MM/YYYY')"),
                'PROP_RE_VALOR'      => $valor,
                'PROP_CH_STATUS'     => $status,
                'PROP_ST_OBSERVACAO' => '',
                'PROP_IN_PERC'       => $porcentagem,
                'PROP_DT_IMPORT'     => DB::raw("TO_DATE('$dataImporta', 'YYYY-MM-DD HH24:MI:SS')"),
            ]);
    }

    public static function hasParcelasMega(
        string $cpfCliente,
        int $codUnidade,
        int $codProposta,
        string $tipoParcela,
        int|float|null $porcentagem = null,
        ?int $numeroParcela = null,
        ?string $dataVencimento = null,
        ?string $valor = null,
        ?string $status = null
    ): int {
        return self::connection()
            ->table('bild.ALX_CLIINTPROPTERMOPARC')
            ->where([
                ['prop_cliente', $cpfCliente],
                ['est_in_codigo', $codUnidade],
                ['prop_in_prop', $codProposta],
                ['prop_ch_parc', $tipoParcela],
            ])
            ->when($dataVencimento, function (OracleBuilder $query) use ($dataVencimento) {
                $query->where('PROP_DT_VENCTO', DB::raw("TO_DATE('$dataVencimento', 'DD/MM/YYYY')"));
            })
            ->when($porcentagem, function (OracleBuilder $query) use ($porcentagem) {
                $query->where('PROP_IN_PERC', $porcentagem);
            })
            ->when($numeroParcela, function (OracleBuilder $query) use ($numeroParcela) {
                $query->where('PROP_IN_PARC', $numeroParcela);
            })
            ->when($valor, function (OracleBuilder $query) use ($valor) {
                $query->where('PROP_RE_VALOR', $valor);
            })
            ->when($status, function (OracleBuilder $query) use ($status) {
                $query->where('PROP_CH_STATUS', $status);
            })
            ->count();
    }

    public static function addParcelasCorrecaoMega(
        string $cpfCliente,
        string $codUnidade,
        string $codProposta,
        int $numeroParcela,
        int $indice,
        string $dataVigencia,
        int $defasagem,
        string $reajuste,
        string $juros,
        string $tipoJuro,
        string $vincula,
        string $dataImporta
    ): void {
        self::connection()
            ->table('bild.ALX_CLIINTPROPTERCOR')
            ->insert([
                'PROP_CLIENTE'   => $cpfCliente,
                'EST_IN_CODIGO'  => $codUnidade,
                'PROP_IN_PROP'   => $codProposta,
                'PROP_IN_PARC'   => $numeroParcela,
                'PROP_IN_INDICE' => $indice,
                'PROP_DT_VIGEN'  => DB::raw("TO_DATE('$dataVigencia', 'DD/MM/YYYY')"),
                'PROP_IN_DEFAS'  => $defasagem,
                'PROP_CH_JUROS'  => $reajuste,
                'PROP_RE_JUROS'  => $juros,
                'PROP_CH_TPJUR'  => $tipoJuro,
                'PROP_CH_VINC'   => $vincula,
                'PROP_DT_IMPORT' => DB::raw("TO_DATE('$dataImporta', 'YYYY-MM-DD HH24:MI:SS')"),
            ]);
    }

    public static function hasParcelasCorrecaoMega(
        string $cpfCliente,
        int $codUnidade,
        int $codProposta,
        ?int $numeroParcela = null,
        ?int $indice = null,
        ?string $dataVigencia = null,
        ?int $defasagem = null,
        ?string $reajuste = null,
        ?string $juros = null,
        ?string $tipoJuro = null,
        ?string $vincula = null,
    ): int {
        return self::connection()
            ->table('bild.ALX_CLIINTPROPTERCOR')
            ->where([
                ['prop_cliente', $cpfCliente],
                ['est_in_codigo', $codUnidade],
                ['prop_in_prop', $codProposta],
            ])
            ->when($numeroParcela, function (OracleBuilder $query) use ($numeroParcela) {
                $query->where('PROP_IN_PARC', $numeroParcela);
            })
            ->when($indice, function (OracleBuilder $query) use ($indice) {
                $query->where('PROP_IN_INDICE', $indice);
            })
            ->when($dataVigencia, function (OracleBuilder $query) use ($dataVigencia) {
                $query->where('PROP_DT_VIGEN', DB::raw("TO_DATE('$dataVigencia', 'DD/MM/YYYY')"));
            })
            ->when($defasagem, function (OracleBuilder $query) use ($defasagem) {
                $query->where('PROP_IN_DEFAS', $defasagem);
            })
            ->when($reajuste, function (OracleBuilder $query) use ($reajuste) {
                $query->where('PROP_CH_JUROS', $reajuste);
            })
            ->when($juros, function (OracleBuilder $query) use ($juros) {
                $query->where('PROP_RE_JUROS', $juros);
            })
            ->when($tipoJuro, function (OracleBuilder $query) use ($tipoJuro) {
                $query->where('PROP_CH_TPJUR', $tipoJuro);
            })
            ->when($vincula, function (OracleBuilder $query) use ($vincula) {
                $query->where('PROP_CH_VINC', $vincula);
            })
            ->count();
    }

    public static function finalizacaoMega(int $codigoMega, string $dataIntegracao, string $codProposta): void
    {
        self::connection()
            ->table('bild.ALX_CLIINTEGRACAOSYS')
            ->insert([
                'INT_IN_CODIGO' => $codigoMega,
                'INT_DT_DATA'   => DB::raw("TO_DATE('$dataIntegracao', 'DD/MM/YYYY HH24:MI:SS')"),
                'INT_IN_DOCTO'  => $codProposta,
            ]);
    }

    public static function consultaContrato(int $idUnidade, string $cpfCliente, ?int $codContrato): SupportCollection
    {
        return self::connection()
            ->table('bild.car_contrato as cto')
            ->select([
                'cto.cto_in_codigo as CONTRATO',
                'cto.org_in_codigo',
                DB::raw("CASE cli.agn_ch_tipopessoafj WHEN 'J' THEN cli.agn_st_cgc ELSE fis.agn_st_cpf END as CPF_CNPJ"),
                DB::raw(
                    "CASE cto.cto_ch_status
                        WHEN 'A' THEN 'Ativo'
                        WHEN 'U' THEN 'Inadimplente'
                        WHEN 'D' THEN 'Distratado'
                        WHEN 'T' THEN 'Transferido'
                        WHEN 'C' THEN 'Cessão de Direitos'
                        WHEN 'Q' THEN 'Quitado'
                    END as STATUS"
                ),
                'env.est_in_codigo as COD_EXPORTA',
                DB::raw("TO_CHAR(oco.oco_dt_cadastro, 'YYYY-MM-DD HH24:MI:SS') as DATA_IMPORTACAO"),
                'emp.est_in_codigo as COD_EMPREENDIMENTO',
                'emp.est_st_nome as EMPREENDIMENTO',
                'blo.est_in_codigo as COD_BLOCO',
                'blo.est_st_nome as BLOCO',
                'uni.est_st_codigo as COD_ST_UNIDADE',
                'uni.est_st_nome as UNIDADE',
                'oco.oco_dt_cadastro',
            ])
            ->join('bild.car_contrato_envolvido as env', function (JoinClause $join) {
                $join->on('cto.cto_in_codigo', '=', 'env.cto_in_codigo')
                    ->on('cto.org_tab_in_codigo', '=', 'env.org_tab_in_codigo')
                    ->on('cto.org_pad_in_codigo', '=', 'env.org_pad_in_codigo')
                    ->on('cto.org_in_codigo', '=', 'env.org_in_codigo')
                    ->on('cto.org_tau_st_codigo', '=', 'env.org_tau_st_codigo');
            })
            ->join('bild.dbm_unidade as un1', function (JoinClause $join) {
                $join->on('env.est_in_codigo', '=', 'un1.est_in_codigo')
                    ->on('env.org_in_codigo', '=', 'un1.org_in_codigo')
                    ->on('env.org_tau_st_codigo', '=', 'un1.org_tau_st_codigo')
                    ->on('env.org_pad_in_codigo', '=', 'un1.org_pad_in_codigo')
                    ->on('env.org_tab_in_codigo', '=', 'un1.org_tab_in_codigo');
            })
            ->join('bild.dbm_estrutura as emp', function (JoinClause $join) {
                $join->on('un1.emp_est_in_codigo', '=', 'emp.est_in_codigo')
                    ->on('un1.org_in_codigo', '=', 'emp.org_in_codigo')
                    ->on('un1.org_tau_st_codigo', '=', 'emp.org_tau_st_codigo')
                    ->on('un1.org_pad_in_codigo', '=', 'emp.org_pad_in_codigo')
                    ->on('un1.org_tab_in_codigo', '=', 'emp.org_tab_in_codigo');
            })
            ->join('bild.dbm_estrutura as blo', function (JoinClause $join) {
                $join->on('un1.blo_est_in_codigo', '=', 'blo.est_in_codigo')
                    ->on('un1.org_in_codigo', '=', 'blo.org_in_codigo')
                    ->on('un1.org_tau_st_codigo', '=', 'blo.org_tau_st_codigo')
                    ->on('un1.org_pad_in_codigo', '=', 'blo.org_pad_in_codigo')
                    ->on('un1.org_tab_in_codigo', '=', 'blo.org_tab_in_codigo');
            })
            ->join('bild.dbm_estrutura as uni', function (JoinClause $join) {
                $join->on('un1.est_in_codigo', '=', 'uni.est_in_codigo')
                    ->on('un1.org_in_codigo', '=', 'uni.org_in_codigo')
                    ->on('un1.org_tau_st_codigo', '=', 'uni.org_tau_st_codigo')
                    ->on('un1.org_pad_in_codigo', '=', 'uni.org_pad_in_codigo')
                    ->on('un1.org_tab_in_codigo', '=', 'uni.org_tab_in_codigo');
            })
            ->join('bild.car_contrato_cliente as ccl', function (JoinClause $join) {
                $join->on('cto.cto_in_codigo', '=', 'ccl.cto_in_codigo')
                    ->on('cto.org_tab_in_codigo', '=', 'ccl.org_tab_in_codigo')
                    ->on('cto.org_pad_in_codigo', '=', 'ccl.org_pad_in_codigo')
                    ->on('cto.org_in_codigo', '=', 'ccl.org_in_codigo')
                    ->on('cto.org_tau_st_codigo', '=', 'ccl.org_tau_st_codigo');
            })
            ->join('bild.glo_agentes as cli', function (JoinClause $join) {
                $join->on('ccl.agn_tab_in_codigo', '=', 'cli.agn_tab_in_codigo')
                    ->on('ccl.agn_pad_in_codigo', '=', 'cli.agn_pad_in_codigo')
                    ->on('ccl.agn_in_codigo', '=', 'cli.agn_in_codigo');
            })
            ->leftJoin('bild.glo_pessoa_fisica as fis', function (JoinClause $join) {
                $join->on('cli.agn_tab_in_codigo', '=', 'fis.agn_tab_in_codigo')
                    ->on('cli.agn_pad_in_codigo', '=', 'fis.agn_pad_in_codigo')
                    ->on('cli.agn_in_codigo', '=', 'fis.agn_in_codigo')
                    ->where('fis.agn_ch_tipo', '=', 'P');
            })
            ->join('bild.dbm_ocorrenciacontrato as oct', function (JoinClause $join) {
                $join->on('cto.cto_in_codigo', '=', 'oct.cto_in_codigo')
                    ->on('cto.org_tab_in_codigo', '=', 'oct.org_tab_in_codigo')
                    ->on('cto.org_pad_in_codigo', '=', 'oct.org_pad_in_codigo')
                    ->on('cto.org_in_codigo', '=', 'oct.org_in_codigo')
                    ->on('cto.org_tau_st_codigo', '=', 'oct.org_tau_st_codigo');
            })
            ->join('bild.dbm_geraocorrencia as oco', 'oct.oco_in_codigo', '=', 'oco.oco_in_codigo')
            ->where('oco.ocs_in_codigo', 44)
            ->where('oco.ocs_in_modulo', 1)
            ->whereNotNull('env.est_in_codigo')
            ->where('cto.cto_ch_tipo', 'V')
            ->where('cto.cto_ch_status', 'A')
            ->whereRaw("bild.alx_pck_utilafh.F_StatusAgente(cto.org_tab_in_codigo, cto.org_pad_in_codigo, cto.fil_in_codigo, 'G') = 'A'")
            ->where(function (Builder $query) use ($idUnidade, $cpfCliente, $codContrato) {
                $query->where(function (Builder $subQuery) use ($idUnidade, $cpfCliente) {
                    $subQuery->where('env.est_in_codigo', $idUnidade)
                        ->whereRaw("CASE cli.agn_ch_tipopessoafj WHEN 'J' THEN cli.agn_st_cgc ELSE fis.agn_st_cpf END = ?", [$cpfCliente]);
                })
                    ->orWhere('cto.cto_in_codigo', $codContrato);
            })
            ->limit(3)
            ->orderByDesc('oco.oco_dt_cadastro')
            ->get();
    }

    public static function addVinculoMega(
        int $codFilialMega,
        string $codContrato,
        string $cpfCompradorPrincipal,
        string $cpfVinculado,
        int $grau,
        string $percentual,
        string $tipo
    ): void {
        $pdo = self::connection()->getPdo();
        $stmt = $pdo->prepare('BEGIN
                bild.PRC_BLD_ALL_RENDA_AVAL(
                    :p_org_in,
                    :p_cto_in,
                    :p_agn_pr,
                    :p_agn_rd,
                    :p_grau,
                    :p_perc,
                    :p_tipo
                );
            END;');

        $stmt->bindParam(':p_org_in', $codFilialMega, PDO::PARAM_INT);
        $stmt->bindParam(':p_cto_in', $codContrato, PDO::PARAM_INT);
        $stmt->bindParam(':p_agn_pr', $cpfCompradorPrincipal, PDO::PARAM_STR);
        $stmt->bindParam(':p_agn_rd', $cpfVinculado, PDO::PARAM_STR);
        $stmt->bindParam(':p_grau', $grau, PDO::PARAM_INT);
        $stmt->bindParam(':p_perc', $percentual, PDO::PARAM_STR);
        $stmt->bindParam(':p_tipo', $tipo, PDO::PARAM_STR);

        $stmt->execute();
    }

    public static function insereParcelas(
        int $idVenda,
        string $documento,
        int $codigoExportaUnidade,
        string $vencimento,
        float $valor,
        int $numeroParcela,
        float $vgvPraticado
    ): void {
        self::connection()
            ->table('bild.CLI_PROPOSTA_SYS')
            ->insert([
                'PROP_IN_CODIGO'   => $idVenda,
                'DOCUMENTO'        => $documento,
                'EST_IN_CODIGO'    => $codigoExportaUnidade,
                'PROP_VENCTO'      => DB::raw("TO_DATE('$vencimento', 'DD/MM/YYYY')"),
                'VLR_PARCELA'      => $valor,
                'PROP_IN_PARCELA'  => $numeroParcela,
                'PROP_RE_VALOR'    => $vgvPraticado,
                'PROP_RE_TT_SINAL' => $valor,
                'VLR_PRESTAMISTA'  => null,
            ]);
    }

    public static function alteraVencimentoParcela(int $idVenda, int $propInSequencia, string $vencimento): bool
    {
        $parcela = self::connection()
            ->table('bild.CLI_PROPOSTA_SYS')
            ->select([
                'PROP_IN_CODIGO',
                'DOCUMENTO',
                'EST_IN_CODIGO',
                'VLR_PARCELA',
                'PROP_IN_PARCELA',
            ])
            ->where([
                ['PROP_IN_CODIGO', $idVenda],
                ['PROP_IN_SEQUENCIA', $propInSequencia],
            ])
            ->first();

        if (! $parcela) {
            return false;
        }

        return self::connection()
            ->table('bild.CLI_PROPOSTA_SYS')
            ->insert([
                'PROP_IN_CODIGO'  => $parcela->prop_in_codigo,
                'DOCUMENTO'       => $parcela->documento,
                'EST_IN_CODIGO'   => $parcela->est_in_codigo,
                'PROP_VENCTO'     => $vencimento,
                'VLR_PARCELA'     => $parcela->vlr_parcela,
                'PROP_IN_PARCELA' => $parcela->prop_in_parcela,
            ]);
    }

    public static function atualizaParcela(int $idVenda, int $propInSequencia, array $columns): bool
    {
        return self::connection()
            ->table('bild.CLI_PROPOSTA_SYS')
            ->where([
                ['PROP_IN_CODIGO', $idVenda],
                ['PROP_IN_SEQUENCIA', $propInSequencia],
            ])
            ->update($columns);
    }

    public static function proximaParcelaMegaFinnet(int $idVenda): ?object
    {
        return self::connection()
            ->table('bild.CLI_PROPOSTA_SYS')
            ->selectRaw('MAX(PROP_IN_PARCELA) +1 AS NUM_PROXIMA_PARCELA')
            ->where('PROP_IN_CODIGO', $idVenda)
            ->first();
    }

    public static function getSequenciaParcelaAtualizada(int $idVenda, int $propInSequencia): ?object
    {
        return self::connection()
            ->table('bild.cli_proposta_sys p')
            ->select([
                'p2.prop_in_sequencia',
            ])
            ->join('bild.cli_proposta_sys p2', function (JoinClause $query) {
                $query->on('p2.prop_in_codigo', '=', 'p.prop_in_codigo')
                    ->on('p2.vlr_parcela', '=', 'p.vlr_parcela')
                    ->on('p2.prop_in_parcela', '=', 'p.prop_in_parcela')
                    ->on('p2.documento', '=', 'p.documento')
                    ->on('p2.est_in_codigo', '=', 'p.est_in_codigo')
                    ->on('p2.status', '!=', 'p.status');
            })
            ->where([
                ['p.prop_in_codigo', $idVenda],
                ['p.prop_in_sequencia', $propInSequencia],
            ])
            ->first();
    }

    public static function atualizaParcelas(
        int $idVenda,
        int $numeroParcela,
        string $vencimentoEn,
        string $documentoHash,
        string $arquivoBase64,
        string $mensagem,
        string $status,
        array $pix = []
    ): void {
        $lengthSize = 30_000;
        $arquivoBase64_1 = substr($arquivoBase64, 0, $lengthSize);
        $arquivoBase64_2 = substr($arquivoBase64, 30_000, $lengthSize);
        $arquivoBase64_3 = substr($arquivoBase64, 60_000, $lengthSize);
        $arquivoBase64_4 = substr($arquivoBase64, 90_000, $lengthSize);
        $arquivoBase64_5 = substr($arquivoBase64, 120_000, $lengthSize);
        $arquivoBase64_6 = substr($arquivoBase64, 150_000, $lengthSize);
        $arquivoBase64_7 = substr($arquivoBase64, 180_000, $lengthSize);
        $arquivoBase64_8 = substr($arquivoBase64, 210_000, $lengthSize);
        $arquivoBase64_9 = substr($arquivoBase64, 240_000, $lengthSize);
        $arquivoBase64_10 = substr($arquivoBase64, 270_000, $lengthSize);
        $arquivoBase64_11 = substr($arquivoBase64, 300_000, $lengthSize);

        $qrcode = $pix['qr_code'] ?? null;
        $txId = $pix['tx_id'] ?? null;
        $emv = $pix['emv'] ?? null;

        $pdo = self::connection()->getPdo();
        $stmt = $pdo->prepare('
            BEGIN
                bild.pck_bld_integracao_orcl.fnc_bld_ret_api_sys(
                    :idVenda,
                    :numeroParcela,
                    :vencimentoEn,
                    :documentoHash,
                    TO_CLOB(:arquivoBase64_1),
                    TO_CLOB(:arquivoBase64_2),
                    TO_CLOB(:arquivoBase64_3),
                    TO_CLOB(:arquivoBase64_4),
                    TO_CLOB(:arquivoBase64_5),
                    TO_CLOB(:arquivoBase64_6),
                    TO_CLOB(:arquivoBase64_7),
                    TO_CLOB(:arquivoBase64_8),
                    TO_CLOB(:arquivoBase64_9),
                    TO_CLOB(:arquivoBase64_10),
                    TO_CLOB(:arquivoBase64_11),
                    :mensagem,
                    :status,
                    :qrcode,
                    :txId,
                    :emv
                );
            END;
        ');

        $stmt->bindParam(':idVenda', $idVenda, PDO::PARAM_INT);
        $stmt->bindParam(':numeroParcela', $numeroParcela, PDO::PARAM_INT);
        $stmt->bindParam(':vencimentoEn', $vencimentoEn, PDO::PARAM_STR);
        $stmt->bindParam(':documentoHash', $documentoHash, PDO::PARAM_STR);
        $stmt->bindParam(':arquivoBase64_1', $arquivoBase64_1, PDO::PARAM_LOB);
        $stmt->bindParam(':arquivoBase64_2', $arquivoBase64_2, PDO::PARAM_LOB);
        $stmt->bindParam(':arquivoBase64_3', $arquivoBase64_3, PDO::PARAM_LOB);
        $stmt->bindParam(':arquivoBase64_4', $arquivoBase64_4, PDO::PARAM_LOB);
        $stmt->bindParam(':arquivoBase64_5', $arquivoBase64_5, PDO::PARAM_LOB);
        $stmt->bindParam(':arquivoBase64_6', $arquivoBase64_6, PDO::PARAM_LOB);
        $stmt->bindParam(':arquivoBase64_7', $arquivoBase64_7, PDO::PARAM_LOB);
        $stmt->bindParam(':arquivoBase64_8', $arquivoBase64_8, PDO::PARAM_LOB);
        $stmt->bindParam(':arquivoBase64_9', $arquivoBase64_9, PDO::PARAM_LOB);
        $stmt->bindParam(':arquivoBase64_10', $arquivoBase64_10, PDO::PARAM_LOB);
        $stmt->bindParam(':arquivoBase64_11', $arquivoBase64_11, PDO::PARAM_LOB);
        $stmt->bindParam(':mensagem', $mensagem, PDO::PARAM_STR);
        $stmt->bindParam(':status', $status, PDO::PARAM_STR);

        if ($qrcode === null) {
            $stmt->bindParam(':qrcode', $qrcode, PDO::PARAM_NULL);
        } else {
            $stmt->bindParam(':qrcode', $qrcode, PDO::PARAM_STR);
        }

        if ($txId === null) {
            $stmt->bindParam(':txId', $txId, PDO::PARAM_NULL);
        } else {
            $stmt->bindParam(':txId', $txId, PDO::PARAM_STR);
        }

        if ($emv === null) {
            $stmt->bindParam(':emv', $emv, PDO::PARAM_NULL);
        } else {
            $stmt->bindParam(':emv', $emv, PDO::PARAM_STR);
        }

        $stmt->execute();
    }

    public static function executaProcedureParcelaFinnet(int $idVenda): void
    {
        $pdo = self::connection()->getPdo();
        $stmt = $pdo->prepare(
            'BEGIN
                bild.pck_bld_integracao_orcl.f_json_sys(:idVenda);
            END;'
        );

        $stmt->bindParam(':idVenda', $idVenda, PDO::PARAM_INT);

        $stmt->execute();
    }

    public static function listarAgenteFinanceiro(int $codFilialMega): SupportCollection
    {
        return self::connection()
            ->table(DB::raw('
                bild.GLO_CONTASFIN A,
                bild.GLO_CONTAS_ORG B,
                bild.GLO_AGENTES C
           '))
            ->select([
                'C.AGN_IN_CODIGO',
                'C.AGN_ST_FANTASIA',
                'A.BAN_IN_NUMERO',
                'A.CTA_ST_NUMERO',
            ])
            ->where([
                ['C.AGN_ST_FANTASIA', 'NOT LIKE', '%APLICACAO%'],
                ['C.AGN_ST_FANTASIA', 'NOT LIKE', '%INATIVA%'],
                ['B.ORG_TAB_IN_CODIGO', 53],
                ['B.ORG_PAD_IN_CODIGO', 1],
                ['B.ORG_TAU_ST_CODIGO', 'G'],
                ['B.ORG_IN_CODIGO', $codFilialMega],
            ])
            ->whereColumn([
                ['A.AGN_IN_CODIGO', 'B.AGN_IN_CODIGO'],
                ['A.AGN_PAD_IN_CODIGO', 'B.AGN_PAD_IN_CODIGO'],
                ['A.AGN_TAB_IN_CODIGO', 'B.AGN_TAB_IN_CODIGO'],
                ['A.AGN_IN_CODIGO', 'C.AGN_IN_CODIGO'],
                ['A.AGN_PAD_IN_CODIGO', 'C.AGN_PAD_IN_CODIGO'],
                ['A.AGN_TAB_IN_CODIGO', 'C.AGN_TAB_IN_CODIGO'],
            ])
            ->orderBy('C.AGN_ST_FANTASIA')
            ->get();
    }

    public static function getCliente(int $codUnidade, string $cpf): SupportCollection
    {
        return self::connection()
            ->table('bild.ALX_VIW_BLDALLAVRATST')
            ->where([
                'idunidade' => $codUnidade,
                'documento' => $cpf,
            ])
            ->get();
    }

    private static function formatSqlForEfetivacao(array $data): string
    {
        $sql = '';
        $total = count($data);
        $increment = 0;

        foreach ($data as $key => $value) {
            $increment++;

            $sql .= "{$key} => '{$value}'";

            if ($increment !== $total) {
                $sql .= ',';
            }
        }

        return $sql;
    }

    public static function executaProcedureEfetivacao(array $data): void
    {
        $sql = self::formatSqlForEfetivacao($data);
        $pdo = self::connection()->getPdo();
        $stmt = $pdo->prepare(
            "BEGIN
                bild.pck_bld_importa_proposta.prc_car_importa_proposta($sql);
            END;"
        );

        $stmt->execute();
    }

    public static function insertLogEfetivacao(int $codProposta, string $documento, array $data): void
    {
        $sqlFormated = Str::of(self::formatSqlForEfetivacao($data))
            ->explode(',')
            ->implode(",\n\t");

        $sqlQueryLog = "BEGIN \n\tbild.pck_bld_importa_proposta.prc_car_importa_proposta(\n\t$sqlFormated\n); \nEND;";

        self::connection()
            ->table('bild.cli_log_wscarteira')
            ->insert([
                'prop_in_codigo'   => $codProposta,
                'documento'        => $documento,
                'payload'          => $sqlQueryLog,
                'dt_processamento' => now('America/Sao_Paulo')->toDateTimeString(),
            ]);
    }

    /**
     * Consulta os valores financeiros do AVRA de uma unidade/contrato
     * Retorna todas as linhas encontradas, sem cálculos ou arredondamentos
     *
     * @param  string  $document  CPF ou CNPJ do comprador formatado com máscara
     * @param  string|int  $unitCode  Código da unidade
     * @param  string|int  $contractCode  Código do contrato
     */
    public static function getAvraFinancialValues(string $document, string|int $unitCode, string|int $contractCode): array
    {
        $query = 'SELECT
                avra.CTO_RE_VALORCONTRATO,
                avra.VLR_PAGO,
                avra.BDS_VLR_ORI,
                avra.BDS_PAGO
            FROM bild.alx_viw_bldavra avra
            WHERE avra.DOCUMENTO = :document
              AND avra.IDUNIDADE = :unit_code
              AND avra.CTO_IN_CODIGO = :contract_code';

        return self::connection()->select($query, [
            'document'      => $document,
            'unit_code'     => $unitCode,
            'contract_code' => $contractCode,
        ]);
    }

    /**
     * Consulta os distratos anunciados (status 'A' e tipo 'D')
     */
    public static function getAnnouncedTerminations(): array
    {
        $query = "SELECT
                dis.ORG_IN_CODIGO,
                dis.CTO_IN_CODIGO
            FROM bild.ALX_CLIINTEGRACAODISTRATO dis
            WHERE dis.INTEGRACAO_CH_STATUS = 'A'
              AND dis.ALTERACAO_CH_TIPO = 'D'";

        return self::connection()->select($query);
    }

    /**
     * Marca o distrato como processado (status 'P'). Deve ser chamado explicitamente
     * Retorna a quantidade de linhas afetadas. A escrita é efetivada (commit) ao final
     * da transação, e em caso de falha é feito rollback e a exceção é propagada
     *
     * @param  string|int  $orgCode  Código da organização
     * @param  string|int  $contractCode  Código do contrato
     */
    public static function markTerminationAsProcessed(string|int $orgCode, string|int $contractCode): int
    {
        $query = "UPDATE bild.ALX_CLIINTEGRACAODISTRATO
            SET INTEGRACAO_CH_STATUS = 'P'
            WHERE ORG_IN_CODIGO = :org_code
              AND CTO_IN_CODIGO = :contract_code
              AND INTEGRACAO_CH_STATUS = 'A'
              AND ALTERACAO_CH_TIPO = 'D'";

        $connection = self::connection();

        return $connection->transaction(fn () => $connection->update($query, [
            'org_code'      => $orgCode,
            'contract_code' => $contractCode,
        ]));
    }

    /**
     * Consulta os distratos processados (status 'P' e tipo 'D') dos pares informados
     * Usuário e data são retornados exatamente como o Mega entrega, sem fuso presumido
     *
     * @param  array  $pairs  Lista de pares, ex: [['org' => 1, 'contract' => 10], [1, 11]]
     */
    public static function getProcessedTerminations(array $pairs): array
    {
        if (empty($pairs)) {
            return [];
        }

        $result = [];

        // O Oracle limita a 1000 expressões em uma lista IN
        foreach (array_chunk(array_values($pairs), 500) as $chunk) {
            $bindings = [];
            $placeholders = [];

            foreach ($chunk as $index => $pair) {
                $org = $pair['org'] ?? $pair[0] ?? null;
                $contract = $pair['contract'] ?? $pair[1] ?? null;

                if (! is_array($pair) || ! (is_string($org) || is_int($org)) || ! (is_string($contract) || is_int($contract))) {
                    throw new \InvalidArgumentException('Cada par deve conter organização e contrato (string ou int).');
                }

                $placeholders[] = "(:org_{$index}, :contract_{$index})";
                $bindings["org_{$index}"] = $org;
                $bindings["contract_{$index}"] = $contract;
            }

            $in = implode(', ', $placeholders);

            $query = "SELECT
                    dis.ORG_IN_CODIGO,
                    dis.CTO_IN_CODIGO,
                    dis.USU_ST_NOME,
                    dis.CTO_DT_ALTERACAO
                FROM bild.ALX_CLIINTEGRACAODISTRATO dis
                WHERE dis.INTEGRACAO_CH_STATUS = 'P'
                  AND dis.ALTERACAO_CH_TIPO = 'D'
                  AND (dis.ORG_IN_CODIGO, dis.CTO_IN_CODIGO) IN ({$in})";

            array_push($result, ...self::connection()->select($query, $bindings));
        }

        return $result;
    }
}
