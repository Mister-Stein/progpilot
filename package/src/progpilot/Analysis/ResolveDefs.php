<?php

/*
 * This file is part of ProgPilot, a static analyzer for security
 *
 * @copyright 2017 Eric Therond. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */


namespace progpilot\Analysis;

use PHPCfg\Block;
use PHPCfg\Func;
use PHPCfg\Op;
use PHPCfg\Script;
use PHPCfg\Visitor;
use PHPCfg\Operand;

use progpilot\Objects\MyClass;
use progpilot\Objects\MyOp;
use progpilot\Objects\MyInstance;
use progpilot\Objects\MyDefinition;
use progpilot\Dataflow\Definitions;

class ResolveDefs {

	public static function copy_instance($context, $data, $myfunc_call)
	{
		if($myfunc_call->get_type() == MyOp::TYPE_INSTANCE)
		{
			$mydef = new MyDefinition(
					$myfunc_call->getLine(), 
					$myfunc_call->getColumn(), 
					$myfunc_call->get_name_instance());

			$mydef->set_block_id($myfunc_call->get_block_id());
			$mydef->set_source_myfile($myfunc_call->get_source_myfile());

			$backdef = $myfunc_call->get_back_def();
			//$new_myclass = $backdef->get_myclass();

			$instances = ResolveDefs::select_instances(
					$context, 
					$data->getoutminuskill($mydef->get_block_id()), 
					$mydef, 
					false);

			foreach($instances as $instance)
			{
				$myclasses = $instance->get_all_myclass();

				foreach($myclasses as $myclass)
				{
					$new_myclass = new MyClass($instance->getLine(), 
							$instance->getColumn(),
							$myclass->get_name());

					foreach($myclass->get_properties() as $property)
					{
						$new_property = clone $property;
						$new_myclass->add_property($new_property);
					}

					foreach($myclass->get_methods() as $method)
					{
						$new_method = clone $method;
						$new_myclass->add_method($new_method);
					}

					$backdef->add_myclass($new_myclass);
				}
			}
		}
	}

	public static function instance_build_back($context, $data, $myfunc, $myfunc_call)
	{
		if(!is_null($myfunc) && $myfunc->get_type() == MyOp::TYPE_METHOD)
		{
			if($myfunc_call->get_type() == MyOp::TYPE_INSTANCE)
			{
				$mybackdef = $myfunc_call->get_back_def();
				$myclass = $myfunc->get_myclass();

				$new_myback_myclass = new MyClass(
						$myclass->getLine(), 
						$myclass->getColumn(),
						$myclass->get_name());
				$mybackdef->add_myclass($new_myback_myclass);

				$copy_myclass = clone $myclass;

				foreach($copy_myclass->get_properties() as $property)
				{
					$mydef = new MyDefinition($myfunc->get_last_line(), $myfunc->get_last_column(), "this");
					$mydef->set_type(MyOp::TYPE_PROPERTY);
					$mydef->property->set_name($property->property->get_name());
					$mydef->set_block_id($myfunc->get_last_block_id());
					$mydef->set_source_myfile($mybackdef->get_source_myfile());

					$defs = ResolveDefs::select_definitions($context, 
							$myfunc->get_defs()->getoutminuskill($mydef->get_block_id()), 
							$mydef);

					foreach($defs as $def_found)
					{
						if($def_found->is_tainted())
							$property->set_tainted(true);

						if($def_found->is_sanitized())
						{
							$property->set_sanitized(true);
							foreach($def_found->get_type_sanitized() as $type_sanitized)
								$property->add_type_sanitized($type_sanitized);
						}
					}

					$new_myback_myclass->add_property($property);

					$property->property->set_name($property->property->get_name());
					$property->set_name($mybackdef->get_name());

					ArrayAnalysis::copy_array($context, $myfunc->get_defs()->getoutminuskill($mydef->get_block_id()), $mydef, $mydef->get_array_value(), $property, $property->get_array_value());
				}

				foreach($copy_myclass->get_methods() as $method)
				{
					$new_method = clone $method;
					$new_myback_myclass->add_method($new_method);
				}
			}
		}
	}

	public static function instance_build_this($context, $data, $myfunc, $myfunc_call)
	{
		if(!is_null($myfunc) && $myfunc_call->get_type() == MyOp::TYPE_INSTANCE)
		{
			$myclass = $myfunc->get_myclass();
			$copy_myclass = clone $myclass;

			foreach($copy_myclass->get_properties() as $property)
			{
				$mydef = new MyDefinition($myfunc_call->getLine(), $myfunc_call->getColumn(), $myfunc_call->get_name_instance());
				$mydef->set_type(MyOp::TYPE_PROPERTY);
				$mydef->property->set_name($property->property->get_name());
				$mydef->set_block_id($myfunc_call->get_block_id());
				$mydef->set_source_myfile($myfunc_call->get_source_myfile());

				$defs_found = ResolveDefs::select_properties($context, $data, $mydef, true);

				foreach($defs_found as $def_found)
				{
					if($def_found->get_is_copy_array())
					{
						$property->set_copyarrays($def_found->get_copyarrays());
						$property->set_is_copy_array(true);
					}

					if($def_found->is_tainted())
						$property->set_tainted(true);

					if($def_found->is_sanitized())
					{
						$property->set_sanitized(true);
						foreach($def_found->get_type_sanitized() as $type_sanitized)
							$property->set_type_sanitized($type_sanitized);
					}
				}

				$property->property->set_name($property->property->get_name());
				$property->set_name("this");
			}

			$mythisdef = $myfunc->get_this_def();
			$mythisdef->set_class_name($copy_myclass->get_name());
			$mythisdef->add_myclass($copy_myclass);
		}
	}

	// def1 and def2 defined in different files
	// return true if def1 is deeper by def2
	public static function is_nearest_includes($def1, $def2)
	{
		$def1_includedby_def2 = false;

		$myfile = $def1->get_source_myfile();
		while(!is_null($myfile))
		{
			$myfile_from = $myfile->get_included_from_myfile();
			if(!is_null($myfile_from) && ($myfile_from->get_name() == $def2->get_source_myfile()->get_name()))
			{
				$def1_includedby_def2 = true;
				break;
			}

			$myfile = $myfile_from;
		}

		if(!$def1_includedby_def2)
		{
			$def2_includedby_def1 = false;
			$myfile = $def2->get_source_myfile();
			while(!is_null($myfile))
			{
				$myfile_from = $myfile->get_included_from_myfile();
				if(!is_null($myfile_from) && ($myfile_from->get_name() == $def1->get_source_myfile()->get_name()))
				{
					$def2_includedby_def1 = true;
					break;
				}

				$myfile = $myfile_from;
			}
		}

		// def1 is included by file from def2
		// but def2 defined before or after the include ?
		if($def1_includedby_def2)
		{
			// def2 defined after the include so def2 is deeper
			if(($def2->getLine() > $myfile->getLine()) 
					|| ($def2->getLine() == $myfile->getLine() &&  $def2->getColumn() >= $myfile->getColumn()))
				return false;

			return true;
		}

		// def2 is included by file from def1
		// but def1 defined before or after the include ?
		if($def2_includedby_def1)
		{
			// def1 defined after the include so def1 is deeper
			if(($def1->getLine() > $myfile->getLine()) 
					|| ($def1->getLine() == $myfile->getLine() &&  $def1->getColumn() >= $myfile->getColumn()))
				return true;

			return false;
		}

		return false;
	}

	// return true if op is deeper in code than def
	public static function is_nearest($context, $def1, $def1_line, $def1_column, $def2, $def2_line, $def2_column)
	{
		if($def1->get_source_myfile()->get_name() == $def2->get_source_myfile()->get_name())
		{
			if(($def1_line > $def2_line) || ($def1_line == $def2_line &&  $def1_column >= $def2_column))
				return true;
		}
		else
			return ResolveDefs::is_nearest_includes($def1, $def2);

		return false;
	}

	public static function get_visibility_method($def, $method)
	{
		if(!is_null($def) && $def->get_name() == "this")
			return true;

		if(!is_null($method) 
				&& $method->get_type() == MyOp::TYPE_METHOD 
				&& $method->get_visibility() == "public")
			return true;

		return false;
	}

	public static function get_visibility($def, $property)
	{
		if(!is_null($def) && $def->get_name() == "this")
			return true;

		if(!is_null($property) 
				&& $property->get_type() == MyOp::TYPE_PROPERTY 
				&& $property->property->get_visibility() == "public")
			return true;

		return false;
	}

	public static function select_definitions($context, $data, $defsearch, $instance_with_property = false, $bypass_isnearest = false)
	{
		$defsfound = [];
		if(is_null($data))
			return $defsfound;

		foreach($data as $def)
		{
			if($def->get_name() == $defsearch->get_name() 
					&& $def->get_assign_id() != $defsearch->get_assign_id()
					&& ResolveDefs::is_nearest($context, $defsearch, $defsearch->getLine(), $defsearch->getColumn(), $def, $def->getLine(), $def->getColumn())
					&& (($def->get_array_value() == $defsearch->get_array_value()) || ($def->get_is_copy_array() && $defsearch->get_is_array()) || $bypass_isnearest || $instance_with_property))
			{
				// CA SERT A QUOI ICI REDONDANT AVEC LE DERNIER ?
				if($def->get_type() == MyOp::TYPE_INSTANCE && $defsearch->get_type() == MyOp::TYPE_INSTANCE && !$instance_with_property)
				{		
					$defsfound[$def->get_block_id()][] = $def;
				}

				else if($def->get_type() == MyOp::TYPE_INSTANCE && $defsearch->get_type() == MyOp::TYPE_PROPERTY && $instance_with_property)
				{		
					$myclasses = $def->get_all_myclass();

					foreach($myclasses as $myclass)
					{
						$property = $myclass->get_property($defsearch);

						if(!is_null($property) && ResolveDefs::get_visibility($def, $property))
						{
							$defsfound[$def->get_block_id()][] = $def;
						}
					}
				}

				else if($def->get_type() == MyOp::TYPE_INSTANCE && $defsearch->get_type() == MyOp::TYPE_METHOD && $instance_with_property)
				{		
					$myclasses = $def->get_all_myclass();

					foreach($myclasses as $myclass)
					{
						$method = $myclass->get_method($defsearch->method->get_name());

						if(!is_null($method) && ResolveDefs::get_visibility_method($def, $method))
						{
							$defsfound[$def->get_block_id()][] = $def;   
						}
					}
				}

				else if($def->get_type() == $defsearch->get_type())
				{	
					if($def->get_type() == MyOp::TYPE_PROPERTY && !$instance_with_property
							&& $def->property->get_name() == $defsearch->property->get_name())
						$defsfound[$def->get_block_id()][] = $def;

					else if($def->get_type() == MyOp::TYPE_LITERAL)
						$defsfound[$def->get_block_id()][] = $def;
				}
				// we are looking for the nearest not instance of a property
				else if($def->get_type() != MyOp::TYPE_INSTANCE && $defsearch->get_type() == MyOp::TYPE_PROPERTY)
				{
					$defsfound[$def->get_block_id()][] = $def;
				}
			}
		}

		// si on a trouvé des defs dans le même bloc que la ou on cherche elles killent les autres	
		if(isset($defsfound[$defsearch->get_block_id()]) 
				&& count($defsfound[$defsearch->get_block_id()]) > 0)
			$defsfound_good[$defsearch->get_block_id()] = $defsfound[$defsearch->get_block_id()];
		else 
			$defsfound_good = $defsfound;

		$truedefsfound = [];

		foreach($defsfound_good as $blockdefs)
		{
			$nearestdef = null;

			foreach($blockdefs as $block_id => $deflast)
			{
				if(!$bypass_isnearest)
				{
					if(ResolveDefs::is_nearest($context, $defsearch, $defsearch->getLine(), $defsearch->getColumn(), $deflast, $deflast->getLine(), $deflast->getColumn()))
					{
						if(is_null($nearestdef) || ResolveDefs::is_nearest($context, $deflast, $deflast->getLine(), $deflast->getColumn(), $nearestdef, $nearestdef->getLine(), $nearestdef->getColumn()))
						{
							$nearestdef = $deflast;
						}
					}
				}
				else
					$truedefsfound[] = $deflast;
			}

			if(!is_null($nearestdef) && !$bypass_isnearest)
				$truedefsfound[] = $nearestdef;
		}

		return $truedefsfound;
	}

	public static function select_instances($context, $data, $tempdefa, $instance_with_property)
	{
		$instances_defs = [];

		if($tempdefa->get_type() == MyOp::TYPE_PROPERTY || $tempdefa->get_type() == MyOp::TYPE_METHOD || !$instance_with_property)
		{
			// we can have multiple instances with the same property assigned
			// we are looking for and instance, not a property	
			$copy_tempdefa = clone $tempdefa;

			if(!$instance_with_property)
			{
				$copy_tempdefa->set_type(MyOp::TYPE_INSTANCE);
				$copy_tempdefa->set_is_array(false);
				$copy_tempdefa->set_array_value(false);
			}

			$instances_defs = ResolveDefs::select_definitions(
					$context, 
					$data, 
					$copy_tempdefa, 
					$instance_with_property);
		}

		return $instances_defs;
	}

	public static function select_properties($context, $data, $tempdefa, $bypass_visibility = false)
	{
		$properties_defs = [];

		if($tempdefa->get_type() == MyOp::TYPE_PROPERTY)
		{
			$defs = ResolveDefs::select_definitions(
					$context, 
					$data, 
					$tempdefa,
					false,
					$bypass_visibility);

			if(count($defs) > 0)
			{
				foreach($defs as $defa)
				{	
					if($defa->get_type() == MyOp::TYPE_PROPERTY)
					{
						// if we found a property, we are looking for the nearest instance or not instance
						$instances = ResolveDefs::select_instances($context, $data, $tempdefa, false);

						foreach($instances as $instance)
						{
							if($instance->get_type() == MyOp::TYPE_INSTANCE)
							{
								$tmp_myclasses = $instance->get_all_myclass();

								foreach($tmp_myclasses as $tmp_myclass)
								{
									$property = $tmp_myclass->get_property($tempdefa);

									if(!is_null($property) && (ResolveDefs::get_visibility($tempdefa, $property) || $bypass_visibility))
									{
										// if the instance is nearest (deeper) than the property, it has the priority
										if(ResolveDefs::is_nearest($context, $instance, $instance->getLine(), $instance->getColumn(), $defa, $defa->getLine(), $defa->getColumn()))
											$properties_defs[] = $property;
										// else property exist in the nearest instance but property has the priority
										else
											$properties_defs[] = $defa;    
									}
								}
							}
						}
					}
				}
			}
			else
			{
				// we didn't find a property, we are looking for the nearest instance or not instance
				$instances = ResolveDefs::select_instances($context, $data, $tempdefa, false);

				foreach($instances as $instance)
				{
					if($instance->get_type() == MyOp::TYPE_INSTANCE)
					{
						$tmp_myclasses = $instance->get_all_myclass();

						foreach($tmp_myclasses as $tmp_myclass)
						{
							$property = $tmp_myclass->get_property($tempdefa);

							if(!is_null($property) && (ResolveDefs::get_visibility($tempdefa, $property) || $bypass_visibility))
							{
								$properties_defs[] = $property;    
							}
						}
					}
				}
			}
		}

		return $properties_defs;
	}

	public static function temporary_simple($context, $data, $tempdefa)
	{
		if($tempdefa->get_type() == MyOp::TYPE_PROPERTY)
			$defs = ResolveDefs::select_properties(
					$context, 
					$data->getoutminuskill($tempdefa->get_block_id()), 
					$tempdefa);

		else
			$defs = ResolveDefs::select_definitions(
					$context, 
					$data->getoutminuskill($tempdefa->get_block_id()), 
					$tempdefa);

		$myexpr = $tempdefa->get_exprs()[0]; 

		$gooddefs = [];
		if(count($defs) > 0)
		{
			foreach($defs as $defz)
			{	
				$defaa = ArrayAnalysis::temporary_simple($context, $tempdefa, $defz);

				foreach($defaa as $defa)
				{
					if($defa->is_ref())
					{
						$refdef = new MyDefinition($tempdefa->getLine(), $tempdefa->getColumn(), $defa->get_ref_name());
						$refdef->set_block_id($tempdefa->get_block_id());
						$refdef->set_source_myfile($tempdefa->get_source_myfile());

						if($defa->is_ref_arr())
						{  
							$refdef->set_is_array(true);
							$refdef->set_array_value($defa->get_ref_arr_value());
						}

						$truerefs = ResolveDefs::select_definitions($context, 
								$data->getoutminuskill($refdef->get_block_id()), 
								$refdef); 

						foreach($truerefs as $ref)
						{
							$ref->add_expr($myexpr);
							$myexpr->add_def($ref);

							$gooddefs[] = $ref;
						}

						unset($truerefs);
					}
					else
					{
						$defa->add_expr($myexpr);
						$myexpr->add_def($defa);
						$gooddefs[] = $defa;
					}
				}
			}
		}
		else
		{
			$myexpr->add_def($tempdefa);
			$gooddefs[] = $tempdefa;
		}

		return $gooddefs;
	}
}
