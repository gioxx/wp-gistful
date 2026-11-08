import { registerBlockType } from '@wordpress/blocks';
import metadata from './block.json';
import Edit from './edit';
import transforms from './transforms';

registerBlockType( metadata.name, {
	edit: Edit,
	save: () => null,
	transforms,
} );
