<?php

/**
 * Doctrine form base class that makes it pretty easy to embed one or multiple related forms including creation forms.
 *
 * @package    ahDoctrineEasyEmbeddedRelationsPlugin
 * @subpackage form
 * @author     Daniel Lohse, Steve Guhr <info@asaphosting.de>
 */
abstract class ahBaseFormDoctrine extends sfFormDoctrine
{
  protected $scheduledForDeletion = array(); // related objects scheduled for deletion
  protected $embedRelations = array();
  
  public function embedRelations(array $relations)
  {
    $this->embedRelations = $relations;
    
    $this->getEventDispatcher()->connect('form.post_configure', array($this, 'listenToFormPostConfigureEvent'));
    
    foreach (array_keys($relations) as $relationName)
    {
      if (!isset($relations[$relationName]['noNewForm']) || !$relations[$relationName]['noNewForm'])
      {
        $relation = $this->getObject()->getTable()->getRelation($relationName);
        $formClass = !isset($relations[$relationName]['newFormClass']) ? $relation->getClass().'Form' : $relations[$relationName]['newFormClass'];
        $formArgs = !isset($relations[$relationName]['newFormClassArgs']) ? array() : $relations[$relationName]['newFormClassArgs'];
        $r = new ReflectionClass($formClass);
        
        $newForm = $r->newInstanceArgs(array_merge(array(null), array($formArgs)));
        $newForm->setDefault($relation->getForeignColumnName(), $this->object[$relation->getLocalColumnName()]);
        if (isset($relations[$relationName]['newFormLabel']))
        {
          $newForm->getWidgetSchema()->setLabel($relations[$relationName]['newFormLabel']);
        }
        $this->embedForm('new_'.$relationName, $newForm);
      }
      
      $formClass = !isset($relations[$relationName]['formClass']) ? null : $relations[$relationName]['formClass'];
      $formArgs = array_merge((!isset($relations[$relationName]['formClassArgs']) ? array() : $relations[$relationName]['formClassArgs']), array(array('ah_add_delete_checkbox' => true)));
      $this->embedRelation($relationName, $formClass, $formArgs);
      
      if (
        count($this->getEmbeddedForm($relationName)->getEmbeddedForms()) === 0 && 
        (!isset($relations[$relationName]['displayEmptyRelations']) || !$relations[$relationName]['displayEmptyRelations'])
      )
      {
        unset($this[$relationName]);
      }
    }
    
    $this->getEventDispatcher()->disconnect('form.post_configure', array($this, 'listenToFormPostConfigureEvent'));
  }
  
  public function listenToFormPostConfigureEvent(sfEvent $event)
  {
    $form = $event->getSubject();
    
    if($form instanceof sfFormDoctrine && $form->getOption('ah_add_delete_checkbox', false) && !$form->isNew())
    {
      $form->setWidget('delete_object', new sfWidgetFormInputCheckbox(array('label' => 'Delete')));
      $form->setValidator('delete_object', new sfValidatorPass());
      
      return $form;
    }
    
    return false;
  }
  
  /**
   * Embed a Doctrine_Collection relationship in to a form
   *
   *     [php]
   *     $userForm = new UserForm($user);
   *     $userForm->embedRelation('Groups AS groups');
   *
   * @param  string $relationName  The name of the relation and an optional alias
   * @param  string $formClass     The name of the form class to use
   * @param  array  $formArguments Arguments to pass to the constructor (related object will be shifted onto the front)
   *
   * @throws InvalidArgumentException If the relationship is not a collection
   */
  public function embedRelation($relationName, $formClass = null, $formArgs = array())
  {
    if (false !== $pos = stripos($relationName, ' as '))
    {
      $fieldName = substr($relationName, $pos + 4);
      $relationName = substr($relationName, 0, $pos);
    }
    else
    {
      $fieldName = $relationName;
    }

    $relation = $this->getObject()->getTable()->getRelation($relationName);

    $r = new ReflectionClass(null === $formClass ? $relation->getClass().'Form' : $formClass);

    if ($relation->isOneToOne())
    {
      $relationInformation = $relation->toArray();
      $relatedPk = $relation->getLocalFieldName();
      //sfContext::getInstance()->getLogger()->info(print_r($relation->__toString(), true));
      //sfContext::getInstance()->getLogger()->info(get_class($relation));
      if (get_class($relation) == 'Doctrine_Relation_LocalKey')
      {
        //$relatedObject = $relation->fetchRelatedFor($this->getObject());
        $relatedObject = $relation->getTable()->find($this->object[$relation->getLocalColumnName()]);
        
        //sfContext::getInstance()->getLogger()->info(print_r($this->getObject()->$relationName->toArray(false), true));
        sfContext::getInstance()->getLogger()->info($relation->getLocalColumnName());
        sfContext::getInstance()->getLogger()->info(print_r($this->object->toArray(), true));
        $this->embedForm($fieldName, $r->newInstanceArgs(array_merge(array($relatedObject), $formArgs)));
      } else
      {
        $this->embedForm($fieldName, $r->newInstanceArgs(array_merge(array($this->getObject()->$relationName), $formArgs)));
      }
    }
    else
    {
      $subForm = new sfForm();

      foreach ($this->getObject()->$relationName as $index => $childObject)
      {
        $form = $r->newInstanceArgs(array_merge(array($childObject), $formArgs));

        $subForm->embedForm($index, $form);
        $subForm->getWidgetSchema()->setLabel($index, (string) $childObject);
      }

      $this->embedForm($fieldName, $subForm);
    }
  }
  
  /**
   * Here we just drop the embedded creation forms if no value has been provided for them (this simulates a non-required embedded form),
   * please provide the fields for the related embedded form in the call to $this->embedRelations() so we don't throw validation errors
   * if the user did not want to add a new related object
   *
   * @see sfForm::doBind()
   */
  protected function doBind(array $values)
  {
    foreach ($this->embedRelations as $relationName => $keys)
    {
      if (!isset($keys['noNewForm']) || !$keys['noNewForm'])
      {
        foreach ($keys['considerNewFormEmptyFields'] as $key)
        {
          if ('' === trim($values['new_'.$relationName][$key]))
          {
            unset($values['new_'.$relationName], $this['new_'.$relationName]);
            break;
          }
        }
      }
      
      if (isset($values[$relationName]))
      {
        foreach ($values[$relationName] as $i => $relationValues)
        {
          if (isset($relationValues['delete_object']) && $relationValues['id'])
          {
            $this->scheduledForDeletion[$relationName][$i] = $relationValues['id'];
          }
        }
      }
    }
    
    parent::doBind($values);
  }
  
  /**
   * Updates object with provided values, dealing with eventual relation deletion
   *
   * @see sfFormDoctrine::doUpdateObject()
   */
  protected function doUpdateObject($values)
  {
    if (count($this->scheduledForDeletion))
    {
      foreach ($this->scheduledForDeletion as $relationName => $ids)
      {
        foreach ($ids as $index => $id)
        {
          unset($values[$relationName][$index]);
          unset($this->object[$relationName][$index]);
          Doctrine::getTable((string)$this->getObject()->getTable()->getRelation($relationName)->getClass())->findOneById($id)->delete();
        }
      }
    }
    
    parent::doUpdateObject($values);
  }
  
  /**
   * Saves embedded form objects.
   *
   * @param mixed $con   An optional connection object
   * @param array $forms An array of sfForm instances
   *
   * @see sfFormObject::saveEmbeddedForms()
   */
  public function saveEmbeddedForms($con = null, $forms = null)
  {
    if (null === $con) $con = $this->getConnection();
    if (null === $forms) $forms = $this->getEmbeddedForms();
    
    foreach ($forms as $form)
    {
      if ($form instanceof sfFormObject)
      {
        $relationName = $this->getRelationByEmbeddedFormClass($form);
        
        if (($relationName && !array_key_exists($form->getObject()->getId(), array_flip($this->scheduledForDeletion[$relationName]))) || !$relationName)
        {
          $form->saveEmbeddedForms($con);
          $form->getObject()->save($con);
        }
      }
      else
      {
        $this->saveEmbeddedForms($con, $form->getEmbeddedForms());
      }
    }
  }
  
  /**
   * Get the used relation alias when given an embedded form
   *
   * @param sfForm $form A sfForm instance
   */
  private function getRelationByEmbeddedFormClass($form)
  {
    foreach ($this->getObject()->getTable()->getRelations() as $relation)
    {
      if ($relation->getClass() === get_class($form->getObject()))
      {
        return $relation->getAlias();
      }
    }
    
    return false;
  }
}
