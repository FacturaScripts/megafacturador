<?php

/*
 * This file is part of megafacturador
 * Copyright (C) 2014-2017  Carlos Garcia Gomez  neorazorx@gmail.com
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 * 
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

require_model('albaran_cliente.php');
require_model('albaran_proveedor.php');
require_model('asiento.php');
require_model('asiento_factura.php');
require_model('cliente.php');
require_model('ejercicio.php');
require_model('factura_cliente.php');
require_model('factura_proveedor.php');
require_model('forma_pago.php');
require_model('partida.php');
require_model('proveedor.php');
require_model('regularizacion_iva.php');
require_model('serie.php');
require_model('subcuenta.php');

class megafacturador extends fs_controller
{
   public $numasientos;
   public $opciones;
   public $serie;
   public $url_recarga;
   
   private $asiento_factura;
   private $cliente;
   private $ejercicio;
   private $ejercicios;
   private $forma_pago;
   private $formas_pago;
   private $proveedor;
   private $regularizacion;
   private $total;
   
   public function __construct()
   {
      parent::__construct('megafacturador', 'MegaFacturador', 'ventas', FALSE, TRUE, TRUE);
   }
   
   protected function private_core()
   {
      $this->asiento_factura = new asiento_factura();
      $this->cliente = new cliente();
      $this->ejercicio = new ejercicio();
      $this->ejercicios = array();
      $this->forma_pago = new forma_pago();
      $this->formas_pago = $this->forma_pago->all();
      $this->numasientos = 0;
      $this->proveedor = new proveedor();
      $this->regularizacion = new regularizacion_iva();
      $this->serie = new serie();
      $this->url_recarga = FALSE;
      
      $this->opciones = array(
          'megafac_ventas' => 1,
          'megafac_compras' => 1,
          'megafac_codserie' => '',
          'megafac_fecha' => 'albaran',
          'megafac_hasta' => date('d-m-Y'),
      );
      $fsvar = new fs_var();
      $this->opciones = $fsvar->array_get($this->opciones, FALSE);
      
      /// corregimos el formato de la fecha
      $this->opciones['megafac_hasta'] = date('d-m-Y', strtotime($this->opciones['megafac_hasta']));
      
      if( isset($_REQUEST['megafac_fecha']) )
      {
         $this->opciones['megafac_ventas'] = isset($_REQUEST['megafac_ventas']) ? 1 : 0;
         $this->opciones['megafac_compras'] = isset($_REQUEST['megafac_compras']) ? 1 : 0;
         $this->opciones['megafac_codserie'] = $_REQUEST['megafac_codserie'];
         $this->opciones['megafac_fecha'] = $_REQUEST['megafac_fecha'];
         $this->opciones['megafac_hasta'] = $_REQUEST['megafac_hasta'];
         $fsvar->array_save($this->opciones);
         
         if($_REQUEST['procesar'] == 'TRUE')
         {
            $this->generar_facturas();
         }
      }
      else if( isset($_GET['genasientos']) )
      {
         $this->generar_asientos();
      }
      else
      {
         $this->share_extensions();
      }
      
      $this->numasientos = $this->num_asientos_a_generar();
   }
   
   private function generar_facturas()
   {
      $recargar = FALSE;
      $this->total = 0;
      if($this->opciones['megafac_ventas'])
      {
         foreach($this->pendientes_venta() as $alb)
         {
            if( $this->generar_factura_cliente( array($alb) ) )
            {
               $recargar = TRUE;
            }
            else
            {
               break;
            }
         }
         
         if($recargar)
         {
            $this->new_message($this->total.' '.FS_ALBARANES.' de cliente facturados.');
         }
      }
      
      $this->total = 0;
      if($this->opciones['megafac_compras'])
      {
         foreach($this->pendientes_compra() as $alb)
         {
            if( $this->generar_factura_proveedor( array($alb) ) )
            {
               $recargar = TRUE;
            }
            else
            {
               break;
            }
         }
         
         if($recargar)
         {
            $this->new_message($this->total.' '.FS_ALBARANES.' de proveedor facturados.');
         }
      }
      
      /// ¿Recargamos?
      if( count($this->get_errors()) > 0 )
      {
         $this->new_error_msg('Se han producido errores. Proceso detenido.');
      }
      else if($recargar)
      {
         $this->url_recarga = $this->url().'&megafac_fecha='.$this->opciones['megafac_fecha']
                 .'&megafac_hasta='.$this->opciones['megafac_hasta']
                 .'&megafac_codserie='.$this->opciones['megafac_codserie']
                 .'&procesar=TRUE';
         
         if($this->opciones['megafac_ventas'])
         {
            $this->url_recarga .= '&megafac_ventas=TRUE';
         }
         
         if($this->opciones['megafac_compras'])
         {
            $this->url_recarga .= '&megafac_compras=TRUE';
         }
         
         $this->new_message('Recargando... &nbsp; <i class="fa fa-refresh fa-spin"></i>');
      }
      else
      {
         $this->new_advice('Finalizado. <span class="glyphicon glyphicon-ok" aria-hidden="true"></span>');
      }
   }
   
   private function share_extensions()
   {
      $fsext = new fs_extension();
      $fsext->name = 'megafacturar_albpro';
      $fsext->from = __CLASS__;
      $fsext->to = 'compras_albaranes';
      $fsext->type = 'button';
      $fsext->text = '<i class="fa fa-check-square-o" aria-hidden="true"></i>'
              . '<span class="hidden-xs">&nbsp; megafacturador</span>';
      $fsext->save();
      
      $fsext2 = new fs_extension();
      $fsext2->name = 'megafacturar_albcli';
      $fsext2->from = __CLASS__;
      $fsext2->to = 'ventas_albaranes';
      $fsext2->type = 'button';
      $fsext2->text = '<i class="fa fa-check-square-o" aria-hidden="true"></i>'
              . '<span class="hidden-xs">&nbsp; megafacturador</span>';
      $fsext2->save();
   }
   
   /**
    * Genera una factura a partir de un array de albaranes.
    * @param albaran_cliente $albaranes
    */
   private function generar_factura_cliente($albaranes)
   {
      $continuar = TRUE;
      
      $factura = new factura_cliente();
      $factura->codagente = $albaranes[0]->codagente;
      $factura->codalmacen = $albaranes[0]->codalmacen;
      $factura->coddivisa = $albaranes[0]->coddivisa;
      $factura->tasaconv = $albaranes[0]->tasaconv;
      $factura->codpago = $albaranes[0]->codpago;
      $factura->codserie = $albaranes[0]->codserie;
      $factura->irpf = $albaranes[0]->irpf;
      $factura->numero2 = $albaranes[0]->numero2;
      $factura->observaciones = $albaranes[0]->observaciones;
      
      $factura->apartado = $albaranes[0]->apartado;
      $factura->cifnif = $albaranes[0]->cifnif;
      $factura->ciudad = $albaranes[0]->ciudad;
      $factura->codcliente = $albaranes[0]->codcliente;
      $factura->coddir = $albaranes[0]->coddir;
      $factura->codpais = $albaranes[0]->codpais;
      $factura->codpostal = $albaranes[0]->codpostal;
      $factura->direccion = $albaranes[0]->direccion;
      $factura->nombrecliente = $albaranes[0]->nombrecliente;
      $factura->provincia = $albaranes[0]->provincia;
      
      $factura->envio_apartado = $albaranes[0]->envio_apartado;
      $factura->envio_apellidos = $albaranes[0]->envio_apellidos;
      $factura->envio_ciudad = $albaranes[0]->envio_ciudad;
      $factura->envio_codigo = $albaranes[0]->envio_codigo;
      $factura->envio_codpais = $albaranes[0]->envio_codpais;
      $factura->envio_codpostal = $albaranes[0]->envio_codpostal;
      $factura->envio_codtrans = $albaranes[0]->envio_codtrans;
      $factura->envio_direccion = $albaranes[0]->envio_direccion;
      $factura->envio_nombre = $albaranes[0]->envio_nombre;
      $factura->envio_provincia = $albaranes[0]->envio_provincia;
      
      /// asignamos fecha y ejercicio usando la del albarán
      if( $this->opciones['megafac_fecha'] == 'albaran' )
      {
         $eje0 = $this->get_ejercicio($albaranes[0]->fecha);
         if($eje0)
         {
            $factura->codejercicio = $eje0->codejercicio;
            $factura->set_fecha_hora($albaranes[0]->fecha, $albaranes[0]->hora);
         }
      }
      
      /**
       * Si se ha elegido fecha de hoy o no se ha podido usar la del albarán porque
       * el ejercicio estaba cerrado, asignamos ejercicio para hoy y usamos la mejor
       * fecha y hora.
       */
      if( is_null($factura->codejercicio) )
      {
         $eje0 = $this->ejercicio->get_by_fecha($factura->fecha);
         if($eje0)
         {
            $factura->codejercicio = $eje0->codejercicio;
            $factura->set_fecha_hora($factura->fecha, $factura->hora);
         }
      }
      
      /// calculamos neto e iva
      foreach($albaranes as $alb)
      {
         foreach($alb->get_lineas() as $l)
         {
            $factura->neto += $l->pvptotal;
            $factura->totaliva += $l->pvptotal * $l->iva/100;
            $factura->totalirpf += $l->pvptotal * $l->irpf/100;
            $factura->totalrecargo += $l->pvptotal * $l->recargo/100;
         }
      }
      
      /// redondeamos
      $factura->neto = round($factura->neto, FS_NF0);
      $factura->totaliva = round($factura->totaliva, FS_NF0);
      $factura->totalirpf = round($factura->totalirpf, FS_NF0);
      $factura->totalrecargo = round($factura->totalrecargo, FS_NF0);
      $factura->total = $factura->neto + $factura->totaliva - $factura->totalirpf + $factura->totalrecargo;
      
      /// comprobamos la forma de pago para saber si hay que marcar la factura como pagada
      $formapago = $this->get_forma_pago($factura->codpago);
      if($formapago)
      {
         if($formapago->genrecibos == 'Pagados')
         {
            $factura->pagada = TRUE;
         }
         
         $cliente = $this->cliente->get($factura->codcliente);
         if($cliente)
         {
            $factura->vencimiento = $formapago->calcular_vencimiento($factura->fecha, $cliente->diaspago);
         }
         else
         {
            $factura->vencimiento = $formapago->calcular_vencimiento($factura->fecha);
         }
      }
      
      if(!$eje0)
      {
         $this->new_error_msg("Ningún ejercicio encontrado.");
         $continuar = FALSE;
      }
      else if( $this->regularizacion->get_fecha_inside($factura->fecha) )
      {
         /*
          * comprobamos que la fecha de la factura no esté dentro de un periodo de
          * IVA regularizado.
          */
         $this->new_error_msg('El '.FS_IVA.' de ese periodo ya ha sido regularizado.'
                 . ' No se pueden añadir más facturas en esa fecha.');
         $continuar = FALSE;
      }
      else if( $factura->save() )
      {
         foreach($albaranes as $alb)
         {
            foreach($alb->get_lineas() as $l)
            {
               $n = new linea_factura_cliente();
               $n->idalbaran = $alb->idalbaran;
               $n->idlineaalbaran = $l->idlinea;
               $n->idfactura = $factura->idfactura;
               $n->cantidad = $l->cantidad;
               $n->codimpuesto = $l->codimpuesto;
               $n->descripcion = $l->descripcion;
               $n->dtopor = $l->dtopor;
               $n->irpf = $l->irpf;
               $n->iva = $l->iva;
               $n->pvpsindto = $l->pvpsindto;
               $n->pvptotal = $l->pvptotal;
               $n->pvpunitario = $l->pvpunitario;
               $n->recargo = $l->recargo;
               $n->referencia = $l->referencia;
               $n->codcombinacion = $l->codcombinacion;
               $n->mostrar_cantidad = $l->mostrar_cantidad;
               $n->mostrar_precio = $l->mostrar_precio;
               
               if( !$n->save() )
               {
                  $continuar = FALSE;
                  $this->new_error_msg("¡Imposible guardar la línea el artículo ".$n->referencia."! ");
                  break;
               }
            }
         }
         
         if($continuar)
         {
            foreach($albaranes as $alb)
            {
               $alb->idfactura = $factura->idfactura;
               $alb->ptefactura = FALSE;
               
               if( !$alb->save() )
               {
                  $this->new_error_msg("¡Imposible vincular el ".FS_ALBARAN." con la nueva factura!");
                  $continuar = FALSE;
                  break;
               }
            }
            
            if($continuar)
            {
               $continuar = $this->generar_asiento_cliente($factura);
               $this->total++;
            }
            else
            {
               if( $factura->delete() )
               {
                  $this->new_error_msg("La factura se ha borrado.");
               }
               else
                  $this->new_error_msg("¡Imposible borrar la factura!");
            }
         }
         else
         {
            if( $factura->delete() )
            {
               $this->new_error_msg("La factura se ha borrado.");
            }
            else
               $this->new_error_msg("¡Imposible borrar la factura!");
         }
      }
      else
         $this->new_error_msg("¡Imposible guardar la factura!");
      
      return $continuar;
   }
   
   private function generar_asiento_cliente($factura, $forzar = FALSE, $soloasiento = FALSE)
   {
      $ok = TRUE;
      
      if($this->empresa->contintegrada OR $forzar)
      {
         $this->asiento_factura->soloasiento = $soloasiento;
         $ok = $this->asiento_factura->generar_asiento_venta($factura);
         
         foreach($this->asiento_factura->errors as $err)
         {
            $this->new_error_msg($err);
         }
         
         foreach($this->asiento_factura->messages as $msg)
         {
            $this->new_message($msg);
         }
      }
      
      return $ok;
   }
   
   /**
    * Genera una factura de compra a partir de un array de albaranes.
    * @param albaran_proveedor $albaranes
    * @return type
    */
   private function generar_factura_proveedor($albaranes)
   {
      $continuar = TRUE;
      
      $factura = new factura_proveedor();
      $factura->codagente = $albaranes[0]->codagente;
      $factura->codalmacen = $albaranes[0]->codalmacen;
      $factura->coddivisa = $albaranes[0]->coddivisa;
      $factura->tasaconv = $albaranes[0]->tasaconv;
      $factura->codpago = $albaranes[0]->codpago;
      $factura->codserie = $albaranes[0]->codserie;
      $factura->irpf = $albaranes[0]->irpf;
      $factura->numproveedor = $albaranes[0]->numproveedor;
      $factura->observaciones = $albaranes[0]->observaciones;
      $factura->cifnif = $albaranes[0]->cifnif;
      $factura->nombre = $albaranes[0]->nombre;
      
      /// asignamos fecha y ejercicio usando la del albarán
      if( $this->opciones['megafac_fecha'] == 'albaran' )
      {
         $eje0 = $this->get_ejercicio($albaranes[0]->fecha);
         if($eje0)
         {
            $factura->codejercicio = $eje0->codejercicio;
            $factura->set_fecha_hora($albaranes[0]->fecha, $albaranes[0]->hora);
         }
      }
      
      /**
       * Si se ha elegido fecha de hoy o no se ha podido usar la del albarán porque
       * el ejercicio estaba cerrado, asignamos ejercicio para hoy y usamos la mejor
       * fecha y hora.
       */
      if( is_null($factura->codejercicio) )
      {
         $eje0 = $this->ejercicio->get_by_fecha($factura->fecha);
         if($eje0)
         {
            $factura->codejercicio = $eje0->codejercicio;
            $factura->set_fecha_hora($factura->fecha, $factura->hora);
         }
      }
      
      /// obtenemos los datos actualizados del proveedor
      $proveedor = $this->proveedor->get($albaranes[0]->codproveedor);
      if($proveedor)
      {
         $factura->cifnif = $proveedor->cifnif;
         $factura->codproveedor = $proveedor->codproveedor;
         $factura->nombre = $proveedor->razonsocial;
      }
      
      /// calculamos neto e iva
      foreach($albaranes as $alb)
      {
         foreach($alb->get_lineas() as $l)
         {
            $factura->neto += $l->pvptotal;
            $factura->totaliva += $l->pvptotal * $l->iva/100;
            $factura->totalirpf += $l->pvptotal * $l->irpf/100;
            $factura->totalrecargo += $l->pvptotal * $l->recargo/100;
         }
      }
      
      /// redondeamos
      $factura->neto = round($factura->neto, FS_NF0);
      $factura->totaliva = round($factura->totaliva, FS_NF0);
      $factura->totalirpf = round($factura->totalirpf, FS_NF0);
      $factura->totalrecargo = round($factura->totalrecargo, FS_NF0);
      $factura->total = $factura->neto + $factura->totaliva - $factura->totalirpf + $factura->totalrecargo;
      
      /// comprobamos la forma de pago para saber si hay que marcar la factura como pagada
      $formapago = $this->get_forma_pago($factura->codpago);
      if($formapago)
      {
         if($formapago->genrecibos == 'Pagados')
         {
            $factura->pagada = TRUE;
         }
      }
      
      if(!$eje0)
      {
         $this->new_error_msg("Ningún ejercicio encontrado.");
         $continuar = FALSE;
      }
      else if( $this->regularizacion->get_fecha_inside($factura->fecha) )
      {
         /*
          * comprobamos que la fecha de la factura no esté dentro de un periodo de
          * IVA regularizado.
          */
         $this->new_error_msg('El '.FS_IVA.' de ese periodo ya ha sido regularizado.'
                 . ' No se pueden añadir más facturas en esa fecha.');
         $continuar = FALSE;
      }
      else if( $factura->save() )
      {
         foreach($albaranes as $alb)
         {
            foreach($alb->get_lineas() as $l)
            {
               $n = new linea_factura_proveedor();
               $n->idalbaran = $alb->idalbaran;
               $n->idlineaalbaran = $l->idlinea;
               $n->idfactura = $factura->idfactura;
               $n->cantidad = $l->cantidad;
               $n->codimpuesto = $l->codimpuesto;
               $n->descripcion = $l->descripcion;
               $n->dtopor = $l->dtopor;
               $n->irpf = $l->irpf;
               $n->iva = $l->iva;
               $n->pvpsindto = $l->pvpsindto;
               $n->pvptotal = $l->pvptotal;
               $n->pvpunitario = $l->pvpunitario;
               $n->recargo = $l->recargo;
               $n->referencia = $l->referencia;
               $n->codcombinacion = $l->codcombinacion;
               
               if( !$n->save() )
               {
                  $continuar = FALSE;
                  $this->new_error_msg("¡Imposible guardar la línea el artículo ".$n->referencia."! ");
                  break;
               }
            }
         }
         
         if($continuar)
         {
            foreach($albaranes as $alb)
            {
               $alb->idfactura = $factura->idfactura;
               $alb->ptefactura = FALSE;
               
               if( !$alb->save() )
               {
                  $this->new_error_msg("¡Imposible vincular el ".FS_ALBARAN." con la nueva factura!");
                  $continuar = FALSE;
                  break;
               }
            }
            
            if($continuar)
            {
               $continuar = $this->generar_asiento_proveedor($factura);
               $this->total++;
            }
            else
            {
               if( $factura->delete() )
               {
                  $this->new_error_msg("La factura se ha borrado.");
               }
               else
                  $this->new_error_msg("¡Imposible borrar la factura!");
            }
         }
         else
         {
            if( $factura->delete() )
            {
               $this->new_error_msg("La factura se ha borrado.");
            }
            else
               $this->new_error_msg("¡Imposible borrar la factura!");
         }
      }
      else
         $this->new_error_msg("¡Imposible guardar la factura!");
      
      return $continuar;
   }
   
   private function generar_asiento_proveedor($factura, $forzar = FALSE, $soloasiento = FALSE)
   {
      $ok = TRUE;
      
      if($this->empresa->contintegrada OR $forzar)
      {
         $this->asiento_factura->soloasiento = $soloasiento;
         $ok = $this->asiento_factura->generar_asiento_compra($factura);
         
         foreach($this->asiento_factura->errors as $err)
         {
            $this->new_error_msg($err);
         }
         
         foreach($this->asiento_factura->messages as $msg)
         {
            $this->new_message($msg);
         }
      }
      
      return $ok;
   }
   
   public function pendientes_venta()
   {
      $alblist = array();
      
      $sql = "SELECT * FROM albaranescli WHERE ptefactura = true";
      if($this->opciones['megafac_codserie'] != '')
      {
         $sql .= " AND codserie = ".$this->serie->var2str($this->opciones['megafac_codserie']);
      }
      if($this->opciones['megafac_hasta'])
      {
         $sql .= " AND fecha <= ".$this->serie->var2str($this->opciones['megafac_hasta']);
      }
      
      $data = $this->db->select_limit($sql.' ORDER BY fecha ASC, hora ASC', 20, 0);
      if($data)
      {
         foreach($data as $d)
         {
            $alblist[] = new albaran_cliente($d);
         }
      }
      
      return $alblist;
   }
   
   public function total_pendientes_venta()
   {
      $total = 0;
      
      $sql = "SELECT count(idalbaran) as total FROM albaranescli WHERE ptefactura = true";
      if($this->opciones['megafac_codserie'] != '')
      {
         $sql .= " AND codserie = ".$this->serie->var2str($this->opciones['megafac_codserie']);
      }
      if($this->opciones['megafac_hasta'])
      {
         $sql .= " AND fecha <= ".$this->serie->var2str($this->opciones['megafac_hasta']);
      }
      
      $data = $this->db->select($sql);
      if($data)
      {
         $total = intval($data[0]['total']);
      }
      
      return $total;
   }
   
   public function pendientes_compra()
   {
      $alblist = array();
      
      $sql = "SELECT * FROM albaranesprov WHERE ptefactura = true";
      if($this->opciones['megafac_codserie'] != '')
      {
         $sql .= " AND codserie = ".$this->serie->var2str($this->opciones['megafac_codserie']);
      }
      if($this->opciones['megafac_hasta'])
      {
         $sql .= " AND fecha <= ".$this->serie->var2str($this->opciones['megafac_hasta']);
      }
      
      $data = $this->db->select_limit($sql.' ORDER BY fecha ASC, hora ASC', 20, 0);
      if($data)
      {
         foreach($data as $d)
         {
            $alblist[] = new albaran_proveedor($d);
         }
      }
      
      return $alblist;
   }
   
   public function total_pendientes_compra()
   {
      $total = 0;
      
      $sql = "SELECT count(idalbaran) as total FROM albaranesprov WHERE ptefactura = true";
      if($this->opciones['megafac_codserie'] != '')
      {
         $sql .= " AND codserie = ".$this->serie->var2str($this->opciones['megafac_codserie']);
      }
      if($this->opciones['megafac_hasta'])
      {
         $sql .= " AND fecha <= ".$this->serie->var2str($this->opciones['megafac_hasta']);
      }
      
      $data = $this->db->select($sql);
      if($data)
      {
         $total = intval($data[0]['total']);
      }
      
      return $total;
   }
   
   private function generar_asientos()
   {
      $nuevos = 0;
      
      $sql = "SELECT * FROM facturascli WHERE idasiento IS NULL";
      $data = $this->db->select_limit($sql, 50, 0);
      if($data)
      {
         foreach($data as $d)
         {
            $factura = new factura_cliente($d);
            if( is_null($factura->idasiento) )
            {
               if( $this->generar_asiento_cliente($factura, TRUE, TRUE) )
               {
                  $nuevos++;
               }
               else
               {
                  break;
               }
            }
         }
      }
      $this->new_message($nuevos.' asientos generados para facturas de venta.');
      
      $nuevos = 0;
      $sql = "SELECT * FROM facturasprov WHERE idasiento IS NULL";
      $data = $this->db->select_limit($sql, 50, 0);
      if($data)
      {
         foreach($data as $d)
         {
            $factura = new factura_proveedor($d);
            if( is_null($factura->idasiento) )
            {
               if( $this->generar_asiento_proveedor($factura, TRUE, TRUE) )
               {
                  $nuevos++;
               }
               else
               {
                  break;
               }
            }
         }
      }
      $this->new_message($nuevos.' asientos generados para facturas de compra.');
      
      /// ¿Recargamos?
      if( count($this->get_errors()) > 0 )
      {
         $this->new_error_msg('Se han producido errores. Proceso detenido.');
      }
      else if( $this->num_asientos_a_generar() > 0 )
      {
         $this->url_recarga = $this->url().'&genasientos=TRUE';
         $this->new_message('Recargando... &nbsp; <i class="fa fa-refresh fa-spin"></i>');
      }
   }
   
   private function num_asientos_a_generar()
   {
      $num = 0;
      
      $sql = "SELECT COUNT(idfactura) as num FROM facturascli WHERE idasiento IS NULL;";
      $data = $this->db->select($sql);
      if($data)
      {
         $num += intval($data[0]['num']);
      }
      
      $sql = "SELECT COUNT(idfactura) as num FROM facturasprov WHERE idasiento IS NULL;";
      $data = $this->db->select($sql);
      if($data)
      {
         $num += intval($data[0]['num']);
      }
      
      return $num;
   }
   
   private function get_ejercicio($fecha)
   {
      if( isset($this->ejercicios[$fecha]) )
      {
         return $this->ejercicios[$fecha];
      }
      else
      {
         $eje = $this->ejercicio->get_by_fecha($fecha);
         if($eje)
         {
            $this->ejercicios[$fecha] = $eje;
         }
         
         return $eje;
      }
   }
   
   private function get_forma_pago($codpago)
   {
      $fp = FALSE;
      
      foreach($this->formas_pago as $fp0)
      {
         if($fp0->codpago == $codpago)
         {
            $fp = $fp0;
            break;
         }
      }
      
      return $fp;
   }
}
