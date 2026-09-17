import type {Result} from './types';
let csrf='';
export class ApiError extends Error {constructor(message:string,public status:number){super(message)}}
export async function request(action:string,data?:Record<string,unknown>):Promise<Result>{
 const controller=new AbortController();const timeout=setTimeout(()=>controller.abort(),12000);
 try{
  const response=await fetch(`/api/exploration/?action=${encodeURIComponent(action)}`,{method:data?'POST':'GET',credentials:'same-origin',signal:controller.signal,headers:data?{'Content-Type':'application/json','X-CSRF-Token':csrf}:{},body:data?JSON.stringify(data):undefined});
  const result=await response.json();
  if(!response.ok)throw new ApiError(result.error||'Le jeu ne peut pas répondre.',response.status);
  if(result.csrf)csrf=result.csrf;return result;
 }catch(error){if(error instanceof ApiError)throw error;throw new ApiError('Connexion interrompue. Votre dernière progression sauvegardée sera récupérée.',0)}finally{clearTimeout(timeout)}
}
