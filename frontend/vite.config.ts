import { defineConfig } from 'vite';
export default defineConfig({base:'/adventure/',build:{outDir:'../app/adventure',emptyOutDir:true,manifest:true},server:{proxy:{'/api':'http://pokemon.sauva.internal','/images':'http://pokemon.sauva.internal','/maps':'http://pokemon.sauva.internal'}}});
