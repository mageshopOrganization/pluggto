<?php

class Thirdlevel_Pluggto_Adminhtml_Pluggto_MiscController extends Mage_Adminhtml_Controller_Action
{
    private $attributes = array(
        '0'  => array('name' =>  'width', 'code' => 'pluggto_width', 'label' => 'Largura (Centimetros)', 'type' => 'decimal', 'note' => 'Informe a largura em centimetros do produto'),
        '1'  => array('name' =>  'height', 'code' => 'pluggto_height', 'label' => 'Altura (Centimetros)', 'type' => 'decimal', 'note' => 'Informe a altura em centimetos do produto'),
        '2'  => array('name' =>  'length', 'code' => 'pluggto_length', 'label' => 'Comprimento (Centimetros)', 'type' => 'decimal', 'note' => 'Informe o comprimento em centimetros do produto'),
        '3'  => array('name' =>  'weight', 'code' => 'pluggto_weight', 'label' => 'Peso (Kilos)', 'type' => 'decimal', 'note' => 'Informe o peso em kilos do produto'),
        '4'  => array('name' =>  'brand', 'code' => 'pluggto_brand', 'label' => 'Marca',  'type' => 'varchar', 'note' => 'Informe a Marca/Fabricante do produto'),
        '5'  => array('name' =>  'manufacture_time', 'code' => 'pluggto_manufactoreTime', 'label' => 'Dias para Fabricação', 'type' => 'int', 'note' => 'Dias de fabricação do produto'),
        '6'  => array('name' =>  'handlingTime', 'code' => 'pluggto_handlingTime', 'label' => 'Dias de manuseio ', 'type' => 'int', 'note' => 'Dias para postagem do produto'),
        '7'  => array('name' =>  'ean', 'code' => 'pluggto_ean', 'label' => 'EAN 13', 'type' => 'varchar', 'note' => 'Código de barra do produto com 13 dígitos'),
        '8'  => array('name' =>  'nbm', 'code' => 'pluggto_nbm', 'label' => 'Código NBM', 'type' => 'varchar', 'note' => 'Código NBM (Nomenclatura Brasileira de Mercadorias) do produto'),
        '9'  => array('name' =>  'ncm', 'code' => 'pluggto_ncm', 'label' => 'Código NCM', 'type' => 'varchar', 'note' => 'Código NCM (Nomenclatura Comum do Mercosul) do produto'),
        '10' => array('name' => 'warranty_time', 'code' => 'pluggto_warrantTime', 'label' => 'Meses de Garantia', 'type' => 'int', 'note' => 'Informe a garantia em meses do produto'),
        '11' => array('name' => 'warranty_message', 'code' => 'pluggto_warrantMessage', 'label' => 'Mensagem de Garantia', 'type' => 'varchar', 'note' => 'Descreva a garantia do produto'),
        '12' => array('name' => 'video', 'code' => 'pluggto_video', 'label' => 'Link para vídeo do Produto', 'type' => 'varchar', 'note' => 'Informe o link para vídeo do produto'),
        '13' => array('name' => 'isbn', 'code' => 'pluggto_isbn', 'label' => 'ISBN do produto', 'type' => 'varchar', 'note' => 'Informe o ISBN do produto'),
        '14' => array(
            'name' => 'origin',
            'code' => 'pluggto_origin',
            'label' => 'Procedência',
            'type' => 'select',
            'note' => 'Informe a Origem do Produto',
            'values' => array(
                0 => '0 - Nacional',
                1 => '1 - Importação Direta',
                2 => '2 - Adquirido Mercado Int',
            ),
        ),
    );

    public function _construct()
    {
        parent::_construct();
    }

    protected function _isAllowed(): bool
    {
        return true;
    }

    public function generate_attributesAction()
    {
        $saveConfig = new Mage_Core_Model_Config();

        foreach ($this->attributes as $newAttributeCode) {
            $this->createAttributesAction($newAttributeCode);
            $saveConfig->saveConfig('pluggto/fields/' . $newAttributeCode['name'], substr($newAttributeCode['code'], 0, 30));
        }

        Mage::getSingleton('core/session')->addSuccess(Mage::helper('pluggto')->__('Atributos Criados com Sucesso'));
        $this->_redirect('adminhtml/system_config/edit/section/pluggto');
    }

    public function update_table_priceAction()
    {
        $saveConfig = new Mage_Core_Model_Config();
        $api = Mage::getModel('pluggto/api')->get('users/prices', null, null, true);

        if (isset($api['Body']['results'])) {
            if (!empty($api['Body']['results'])) {
                foreach ($api['Body']['results'] as $priceCode) {
                    $priceCode = $priceCode['code'];

                    // incrementar, decrementar, ou sobreescrever
                    $priceAttribute = array('name' => 'Tipo de Personalização (' . $priceCode . ')', 'code' => "tb_" . $priceCode . "_action", 'label' => 'Operação (' . $priceCode . ')', 'type' => 'select', 'note' => 'Escolha se deverá sobreescrer o preço e preço especial do produto, incrementar ou decrementar', 'values' => array(
                        0 => 'overwrite',
                        1 => 'increase',
                        2 => 'decrease'
                    ));

                    $this->createAttributesAction($priceAttribute, 'Preço ' . ucfirst($priceCode));
                    $saveConfig->saveConfig('pluggto/pricetable/' . $priceCode . '/action', "tb_" . $priceCode . "_action");

                    // preço
                    $priceAttribute = array('name' => 'Preço', 'code' => "tb_" . $priceCode . "_price", 'label' => 'Preço (de) (' . $priceCode . ')', 'type' => 'decimal', 'note' => 'Sobrescreve preço do produto caso a opção sobrescrever esteja setada');
                    $this->createAttributesAction($priceAttribute, 'Preço ' . ucfirst($priceCode));
                    $saveConfig->saveConfig('pluggto/pricetable/' . $priceCode . '/price', "tb_" . $priceCode . "_price");

                    // preço especial
                    $priceAttribute = array('name' => 'Preço Especial', 'code' => "tb_" . $priceCode . "_sprice", 'label' => 'Preço Especial (por) (' . $priceCode . ')', 'type' => 'decimal', 'note' => 'Sobrescreve preço especial do produto caso a opção sobrescrever esteja setada');
                    $this->createAttributesAction($priceAttribute, 'Preço ' . ucfirst($priceCode));
                    $saveConfig->saveConfig('pluggto/pricetable/' . $priceCode . '/special_price', "tb_" . $priceCode . "_sprice");

                    // percentagem
                    $priceAttribute = array('name' => 'Porcentagem', 'code' => "tb_" . $priceCode . "_pc", 'label' => 'Porcentagem de Incremento/Decremento (' . $priceCode . ')', 'type' => 'decimal', 'note' => 'Aplica uma porcentagem de incremento/decremento caso a opção Incrementar/Decrementar esteja setada');
                    $this->createAttributesAction($priceAttribute, 'Preço ' . ucfirst($priceCode));
                    $saveConfig->saveConfig('pluggto/pricetable/' . $priceCode . '/percentage', "tb_" . $priceCode . "_pc");

                    // minimum
                    $priceAttribute = array('name' => 'Preço Mínimo', 'code' => "tb_" . $priceCode . "_min", 'label' => 'Preço mínimo de venda do produto (por) (' . $priceCode . ')', 'type' => 'decimal', 'note' => 'Campo utilizado para funcionalidade de precificação dinâmica');
                    $this->createAttributesAction($priceAttribute, 'Preço ' . ucfirst($priceCode));
                    $saveConfig->saveConfig('pluggto/pricetable/' . $priceCode . '/minimum_price', "tb_" . $priceCode . "_min");

                    // maximum
                    $priceAttribute = array('name' => 'Preço Máximo', 'code' => "tb_" . $priceCode . "_max", 'label' => 'Preço máximo de venda do produto (por) (' . $priceCode . ')', 'type' => 'decimal', 'note' => 'Campo utilizado para funcionalidade de precificação dinâmica');
                    $this->createAttributesAction($priceAttribute, 'Preço ' . ucfirst($priceCode));
                    $saveConfig->saveConfig('pluggto/pricetable/' . $priceCode . '/maximum_price', "tb_" . $priceCode . "_max");
                }
            } else {
                Mage::getSingleton('core/session')->addError(Mage::helper('pluggto')->__('Você não tem nenhuma tabela de preço cadastrada, é necessário cadastrar uma tabela antes no Plugg.to'));
                $this->_redirect('adminhtml/system_config/edit/section/pluggto');
            }
        } else {
            Mage::getSingleton('core/session')->addError(Mage::helper('pluggto')->__('Ocorreu um erro em gerar os atributos de preço'));
            $this->_redirect('adminhtml/system_config/edit/section/pluggto');
        }

        Mage::getSingleton('core/session')->addSuccess(Mage::helper('pluggto')->__('Atributos de preço criados com Sucesso'));
        $this->_redirect('adminhtml/system_config/edit/section/pluggto');
    }

    public function createAttributesAction($newAttributeCode, $group = 'PluggTo')
    {
        //  verificar se attributo existe

        // verficar se attributo existe
        $attribute_model = Mage::getModel('eav/entity_attribute');
        $attribute = $attribute_model->load($attribute_model->getIdByCode('catalog_product', $newAttributeCode['code']));

        // caso encontre atributo, não faça nada
        if ($attribute->getAttributeId() != null) {
            return;
        }

        // caso negativo criar atributo
        $setup = new Mage_Eav_Model_Entity_Setup('core_setup');

        if ($newAttributeCode['type'] == 'select') {
            $input = 'select';
            $newAttributeCode['type'] = 'varchar';
        } else {
            $input = 'text';
        }

        $config = array(
            'group'    => $group,
            'type'     => $newAttributeCode['type'],
            'position' => 1,
            'required' => 0,
            'label'    => $newAttributeCode['label'],
            "visible"  => true,
            'input'    => $input,
            'unique'   => false,
            'apply_to' => 'simple,bundle,grouped,configurable',
            'is_configurable' => '0',
            'is_comparable' => '0',
            'is_searchable' => '0',
            'is_required' => '0',
            'is_visible_on_front' => '0',
            'global' => Mage_Catalog_Model_Resource_Eav_Attribute::SCOPE_GLOBAL
        );

        if (isset($newAttributeCode['values'])) {
            $config['option'] = array('values' => $newAttributeCode['values']);
        }

        if (isset($newAttributeCode['note'])) {
            $config['note'] = $newAttributeCode['note'];
        }

        $setup->addAttribute('catalog_product', substr($newAttributeCode['code'], 0, 30), $config);
    }
}