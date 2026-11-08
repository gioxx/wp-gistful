import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	Placeholder,
	PanelBody,
	TextControl,
	ToggleControl,
	SelectControl,
	ColorPalette,
	Button,
} from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import { useState } from '@wordpress/element';
import metadata from './block.json';

const triState = ( value ) => ( value === undefined ? 'default' : value ? 'yes' : 'no' );
const fromTriState = ( value ) => ( value === 'default' ? undefined : value === 'yes' );

export default function Edit( { attributes, setAttributes } ) {
	const blockProps = useBlockProps();
	const [ draft, setDraft ] = useState( '' );
	const { url, file, lines, linesStart, highlight, highlightColor, showLineNumbers, showMeta, scheme } = attributes;

	const triOptions = [
		{ label: __( 'Use plugin default', 'gistful' ), value: 'default' },
		{ label: __( 'Yes', 'gistful' ), value: 'yes' },
		{ label: __( 'No', 'gistful' ), value: 'no' },
	];

	const controls = (
		<InspectorControls>
			<PanelBody title={ __( 'Gist', 'gistful' ) }>
				<TextControl __nextHasNoMarginBottom __next40pxDefaultSize label={ __( 'Gist URL or ID', 'gistful' ) } value={ url || '' } onChange={ ( v ) => setAttributes( { url: v } ) } />
				<TextControl __nextHasNoMarginBottom __next40pxDefaultSize label={ __( 'File name', 'gistful' ) } value={ file || '' } onChange={ ( v ) => setAttributes( { file: v } ) } />
				<TextControl __nextHasNoMarginBottom __next40pxDefaultSize label={ __( 'Lines (e.g. 2-4,7)', 'gistful' ) } value={ lines || '' } onChange={ ( v ) => setAttributes( { lines: v } ) } />
				<TextControl __nextHasNoMarginBottom __next40pxDefaultSize type="number" label={ __( 'First line number', 'gistful' ) } value={ linesStart ?? '' } onChange={ ( v ) => setAttributes( { linesStart: v === '' ? undefined : parseInt( v, 10 ) } ) } />
				<TextControl __nextHasNoMarginBottom __next40pxDefaultSize label={ __( 'Highlight lines (e.g. 2,5-6)', 'gistful' ) } value={ highlight || '' } onChange={ ( v ) => setAttributes( { highlight: v } ) } />
			</PanelBody>
			<PanelBody title={ __( 'Display', 'gistful' ) } initialOpen={ false }>
				<SelectControl __nextHasNoMarginBottom __next40pxDefaultSize label={ __( 'Line numbers', 'gistful' ) } value={ triState( showLineNumbers ) } options={ triOptions } onChange={ ( v ) => setAttributes( { showLineNumbers: fromTriState( v ) } ) } />
				<SelectControl __nextHasNoMarginBottom __next40pxDefaultSize label={ __( 'Meta footer', 'gistful' ) } value={ triState( showMeta ) } options={ triOptions } onChange={ ( v ) => setAttributes( { showMeta: fromTriState( v ) } ) } />
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Color scheme', 'gistful' ) }
					value={ scheme || '' }
					options={ [
						{ label: __( 'Plugin default', 'gistful' ), value: '' },
						{ label: __( 'Auto', 'gistful' ), value: 'auto' },
						{ label: __( 'Light', 'gistful' ), value: 'light' },
						{ label: __( 'Dark', 'gistful' ), value: 'dark' },
					] }
					onChange={ ( v ) => setAttributes( { scheme: v } ) }
				/>
				<p>{ __( 'Highlight color', 'gistful' ) }</p>
				<ColorPalette
					colors={ [ { name: __( 'Yellow', 'gistful' ), color: '#ffffcc' } ] }
					value={ highlightColor }
					onChange={ ( v ) => setAttributes( { highlightColor: v || '' } ) }
				/>
			</PanelBody>
		</InspectorControls>
	);

	if ( ! url ) {
		return (
			<div { ...blockProps }>
				{ controls }
				<Placeholder icon="editor-code" label={ __( 'GitHub Gist', 'gistful' ) } instructions={ __( 'Paste a gist URL or ID.', 'gistful' ) }>
					<form onSubmit={ ( e ) => { e.preventDefault(); setAttributes( { url: draft.trim() } ); } }>
						<TextControl __nextHasNoMarginBottom __next40pxDefaultSize label={ __( 'Gist URL or ID', 'gistful' ) } hideLabelFromVision value={ draft } onChange={ setDraft } placeholder="https://gist.github.com/user/id" />
						<Button variant="primary" type="submit">{ __( 'Embed', 'gistful' ) }</Button>
					</form>
				</Placeholder>
			</div>
		);
	}

	return (
		<div { ...blockProps }>
			{ controls }
			<ServerSideRender block={ metadata.name } attributes={ attributes } />
		</div>
	);
}
