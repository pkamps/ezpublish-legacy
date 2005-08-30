<?php
//
// Created on: <17-Jan-2003 12:47:11 amos>
//
// Copyright (C) 1999-2005 eZ systems as. All rights reserved.
//
// This source file is part of the eZ publish (tm) Open Source Content
// Management System.
//
// This file may be distributed and/or modified under the terms of the
// "GNU General Public License" version 2 as published by the Free
// Software Foundation and appearing in the file LICENSE included in
// the packaging of this file.
//
// Licencees holding a valid "eZ publish professional licence" version 2
// may use this file in accordance with the "eZ publish professional licence"
// version 2 Agreement provided with the Software.
//
// This file is provided AS IS with NO WARRANTY OF ANY KIND, INCLUDING
// THE WARRANTY OF DESIGN, MERCHANTABILITY AND FITNESS FOR A PARTICULAR
// PURPOSE.
//
// The "eZ publish professional licence" version 2 is available at
// http://ez.no/ez_publish/licences/professional/ and in the file
// PROFESSIONAL_LICENCE included in the packaging of this file.
// For pricing of this licence please contact us via e-mail to licence@ez.no.
// Further contact information is available at http://ez.no/company/contact/.
//
// The "GNU General Public License" (GPL) is available at
// http://www.gnu.org/copyleft/gpl.html.
//
// Contact licence@ez.no if any conditions of this licencing isn't clear to
// you.
//

$Module =& $Params['Module'];
$ObjectID =& $Params['ObjectID'];

include_once( 'kernel/classes/ezcontentobject.php' );
include_once( "lib/ezdb/classes/ezdb.php" );

$http =& eZHTTPTool::instance();

if ( $http->hasPostVariable( 'BrowseCancelButton' ) )
{
    if ( $http->hasPostVariable( 'BrowseCancelURI' ) )
    {
        return $Module->redirectTo( $http->postVariable( 'BrowseCancelURI' ) );
    }
}

if ( $ObjectID === null )
{
    // ObjectID is returned after browsing
    $ObjectID =& $http->postVariable( 'ObjectID' );
}

$object =& eZContentObject::fetch( $ObjectID );

if ( $object === null )
    return $Module->handleError( EZ_ERROR_KERNEL_NOT_AVAILABLE, 'kernel' );

if ( !$object->attribute( 'can_read' ) )
    return $Module->handleError( EZ_ERROR_KERNEL_ACCESS_DENIED, 'kernel' );

if ( $Module->isCurrentAction( 'Cancel' ) )
{
    $mainParentNodeID = $object->attribute( 'main_parent_node_id' );
    return $Module->redirectToView( 'view', array( 'full', $mainParentNodeID ) );
}

$contentINI =& eZINI::instance( 'content.ini' );

/*!
 Copy the specified object to a given node
*/
function copyObject( &$Module, &$object, $allVersions, $newParentNodeID )
{
    if ( !$newParentNodeID )
        return $Module->redirectToView( 'view', array( 'full', 2 ) );

    // check if we can create node under the specified parent node
    if( ( $newParentNode =& eZContentObjectTreeNode::fetch( $newParentNodeID ) ) === null )
        return $Module->redirectToView( 'view', array( 'full', 2 ) );

    $classID = $object->attribute('contentclass_id');

    if ( !$newParentNode->checkAccess( 'create', $classID ) )
    {
        $objectID =& $object->attribute( 'id' );
        eZDebug::writeError( "Cannot copy object $objectID to node $newParentNodeID, " .
                             "the current user does not have create permission for class ID $classID",
                             'content/copy' );
        return $Module->handleError( EZ_ERROR_KERNEL_ACCESS_DENIED, 'kernel' );
    }

    $db =& eZDB::instance();
    $db->begin();
    $newObject =& $object->copy( $allVersions );

    $curVersion        =& $newObject->attribute( 'current_version' );
    $curVersionObject  =& $newObject->attribute( 'current' );
    $newObjAssignments =& $curVersionObject->attribute( 'node_assignments' );
    unset( $curVersionObject );

    // remove old node assignments
    foreach( $newObjAssignments as $assignment )
        $assignment->remove();

    // and create a new one
    $nodeAssignment = eZNodeAssignment::create( array(
                                                     'contentobject_id' => $newObject->attribute( 'id' ),
                                                     'contentobject_version' => $curVersion,
                                                     'parent_node' => $newParentNodeID,
                                                     'is_main' => 1
                                                     ) );
    $nodeAssignment->store();

    // publish the newly created object
    include_once( 'lib/ezutils/classes/ezoperationhandler.php' );
    eZOperationHandler::execute( 'content', 'publish', array( 'object_id' => $newObject->attribute( 'id' ),
                                                              'version'   => $curVersion ) );
    // Update "is_invisible" attribute for the newly created node.
    $newNode =& $newObject->attribute( 'main_node' );
    eZContentObjectTreeNode::updateNodeVisibility( $newNode, $newParentNode );

    $db->commit();
    return $Module->redirectToView( 'view', array( 'full', $newParentNodeID ) );
}

/*!
Browse for node to place the object copy into
*/
function browse( &$Module, &$object )
{
    if ( $Module->hasActionParameter( 'LanguageCode' ) )
        $languageCode = $Module->actionParameter( 'LanguageCode' );
    else
        $languageCode = eZContentObject::defaultLanguage();

    $objectID =& $object->attribute( 'id' );
    $node     =& $object->attribute( 'main_node' );
    $class    =& $object->contentClass();

    $ignoreNodesSelect = array();
    $ignoreNodesClick = array();
    foreach ( $object->assignedNodes( false ) as $element )
    {
        $ignoreNodesSelect[] = $element['node_id'];
        $ignoreNodesClick[]  = $element['node_id'];
    }
    $ignoreNodesSelect = array_unique( $ignoreNodesSelect );
    $ignoreNodesClick = array_unique( $ignoreNodesClick );

    $viewMode = 'full';
    if ( $Module->hasActionParameter( 'ViewMode' ) )
        $viewMode = $module->actionParameter( 'ViewMode' );


    include_once( 'kernel/classes/ezcontentbrowse.php' );
    $sourceParentNodeID = $node->attribute( 'parent_node_id' );
    eZContentBrowse::browse( array( 'action_name' => 'CopyNode',
                                    'description_template' => 'design:content/browse_copy_node.tpl',
                                    'keys' => array( 'class' => $class->attribute( 'id' ),
                                                     'class_id' => $class->attribute( 'identifier' ),
                                                     'classgroup' => $class->attribute( 'ingroup_id_list' ),
                                                     'section' => $object->attribute( 'section_id' ) ),
                                    'ignore_nodes_select' => $ignoreNodesSelect,
                                    'ignore_nodes_click'  => $ignoreNodesClick,
                                    'persistent_data' => array( 'ObjectID' => $objectID ),
                                    'permission' => array( 'access' => 'create', 'contentclass_id' => $class->attribute( 'id' ) ),
                                    'content' => array( 'object_id' => $objectID,
                                                        'object_version' => $object->attribute( 'current_version' ),
                                                        'object_language' => $languageCode ),
                                    'start_node' => $sourceParentNodeID,
                                    'cancel_page' => $Module->redirectionURIForModule( $Module, 'view',
                                                                                       array( $viewMode, $sourceParentNodeID, $languageCode ) ),
                                    'from_page' => "/content/copy" ),
                             $Module );
}

/*!
Redirect to the page that lets a user to choose which versions to copy:
either all version or the current one.
*/
function chooseObjectVersionsToCopy( &$Module, &$Result, &$object )
{
        include_once( 'kernel/classes/ezcontentbrowse.php' );
        $selectedNodeIDArray = eZContentBrowse::result( $Module->currentAction() );
        include_once( 'kernel/common/template.php' );
        $tpl =& templateInit();
        $tpl->setVariable( 'object', $object );
        $tpl->setVariable( 'selected_node_id', $selectedNodeIDArray[0] );
        $Result['content'] = $tpl->fetch( 'design:content/copy.tpl' );
        $Result['path'] = array( array( 'url' => false,
                                        'text' => ezi18n( 'kernel/content', 'Content' ) ),
                                 array( 'url' => false,
                                        'text' => ezi18n( 'kernel/content', 'Copy' ) ) );
}

/*
 Object copying logic in pseudo-code:

 $targetNodeID = browse();
 $versionsToCopy = fetchObjectVersionsToCopyFromContentINI();
 if ( $versionsToCopy != 'user-defined' )
    $versionsToCopy = askUserAboutVersionsToCopy();
 copyObject( $object, $versionsToCopy, $targeNodeID );

 Action parameters:

 1. initially:                                   null
 2. when user has selected the target node:     'CopyNode'
 3. when/if user has selected versions to copy: 'Copy' or 'Cancel'
*/

$versionHandling = $contentINI->variable( 'CopySettings', 'VersionHandling' );
$chooseVersions = ( $versionHandling == 'user-defined' );
if( $chooseVersions )
    $allVersions = ( $Module->actionParameter( 'VersionChoice' ) == 1 ) ? true : false;
else
    $allVersions = ( $versionHandling == 'last-published' ) ? false : true;

if ( $Module->isCurrentAction( 'Copy' ) )
{
    // actually do copying after a user has selected object versions to copy
    $newParentNodeID =& $http->postVariable( 'SelectedNodeID' );
    return copyObject( $Module, $object, $allVersions, $newParentNodeID );
}
else if ( $Module->isCurrentAction( 'CopyNode' ) )
{
    // we get here after a user selects target node to place the source object under
    if( $chooseVersions )
    {
        // redirect to the page with choice of versions to copy
        $Result = array();
        chooseObjectVersionsToCopy( $Module, $Result, $object );
    }
    else
    {
        // actually do copying of the pre-configured object version(s)
        include_once( 'kernel/classes/ezcontentbrowse.php' );
        $selectedNodeIDArray = eZContentBrowse::result( $Module->currentAction() );
        $newParentNodeID =& $selectedNodeIDArray[0];
        return copyObject( $Module, $object, $allVersions, $newParentNodeID );
    }
}
else // default, initial action
{
    /*
    Browse for target node.
    We get here when a user clicks "copy" button when viewing some node.
    */
    browse( $Module, $object );
}

?>
