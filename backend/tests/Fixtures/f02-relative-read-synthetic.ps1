# Synthetic experiment only; no actual credential input and no database access.
Add-Type -TypeDefinition @'
using System;
using System.ComponentModel;
using System.Runtime.InteropServices;
using System.Security.AccessControl;
using System.Security.Principal;
using Microsoft.Win32.SafeHandles;
public static class F02SyntheticRead {
 [DllImport("kernel32.dll",CharSet=CharSet.Unicode,SetLastError=true)] static extern SafeFileHandle CreateFile(string path,uint access,uint share,IntPtr sd,uint disposition,uint flags,IntPtr template);
 [DllImport("ntdll.dll")] static extern int NtCreateFile(out SafeFileHandle handle,uint access,ref OA attributes,out IOS status,IntPtr allocation,uint fileAttributes,uint share,uint disposition,uint options,IntPtr ea,uint eaLength);
 [DllImport("kernel32.dll",SetLastError=true)] static extern bool GetFileInformationByHandle(SafeFileHandle h,out Info i);
 [DllImport("advapi32.dll")] static extern uint GetSecurityInfo(SafeFileHandle h,int type,uint security,out IntPtr owner,out IntPtr group,out IntPtr dacl,out IntPtr sacl,out IntPtr descriptor);
 [DllImport("advapi32.dll")] static extern uint GetSecurityDescriptorLength(IntPtr descriptor);
 [DllImport("kernel32.dll")] static extern IntPtr LocalFree(IntPtr p);
 [StructLayout(LayoutKind.Sequential)] struct IOS { public IntPtr Status, Information; }
 [StructLayout(LayoutKind.Sequential)] struct US { public ushort Length,MaximumLength; public IntPtr Buffer; }
 [StructLayout(LayoutKind.Sequential)] struct OA { public int Length; public IntPtr RootDirectory,ObjectName; public uint Attributes; public IntPtr SecurityDescriptor,SecurityQualityOfService; }
 [StructLayout(LayoutKind.Sequential)] struct Info { public uint Attributes,CreationLow,CreationHigh,AccessLow,AccessHigh,WriteLow,WriteHigh,Volume,SizeHigh,SizeLow,Links,IndexHigh,IndexLow; }
 static void Check(SafeFileHandle h,bool directory) {
  Info i; if(!GetFileInformationByHandle(h,out i)) throw new Win32Exception(Marshal.GetLastWin32Error());
  if((i.Attributes & 0x400)!=0) throw new InvalidOperationException("reparse point refused");
  if(((i.Attributes & 0x10)!=0)!=directory || (!directory && i.Links!=1)) throw new InvalidOperationException("type or link refused");
  IntPtr o,g,d,s,p; uint rc=GetSecurityInfo(h,1,5,out o,out g,out d,out s,out p); if(rc!=0) throw new Win32Exception((int)rc);
  try { byte[] b=new byte[checked((int)GetSecurityDescriptorLength(p))]; Marshal.Copy(p,b,0,b.Length); var sd=new RawSecurityDescriptor(b,0); var sid=WindowsIdentity.GetCurrent().User;
   if(!sid.Equals(sd.Owner) || (sd.ControlFlags & ControlFlags.DiscretionaryAclProtected)==0 || sd.DiscretionaryAcl==null || sd.DiscretionaryAcl.Count!=1) throw new InvalidOperationException("ACL refused");
   var ace=sd.DiscretionaryAcl[0] as CommonAce; if(ace==null || ace.IsCallback || ace.AceQualifier!=AceQualifier.AccessAllowed || ace.IsInherited || (ace.AceFlags & AceFlags.InheritOnly)!=0 || !sid.Equals(ace.SecurityIdentifier) || (ace.AccessMask & 0x1f01ff)!=0x1f01ff || (!directory && ace.AceFlags!=AceFlags.None)) throw new InvalidOperationException("ACL refused");
  } finally { LocalFree(p); }
 }
 public static SafeFileHandle OpenDirectory(string path) {
  var h=CreateFile(path,0x20080,3,IntPtr.Zero,3,0x02200000,IntPtr.Zero); if(h.IsInvalid) throw new Win32Exception(Marshal.GetLastWin32Error());
  try { Check(h,true); return h; } catch { h.Dispose(); throw; }
 }
 public static void ValidateDirectory(SafeFileHandle directory) { Check(directory,true); }
 public static SafeFileHandle OpenFile(SafeFileHandle directory,string name) {
  if(name!="fake.txt") throw new ArgumentException("synthetic filename only");
  IntPtr chars=Marshal.StringToHGlobalUni(name), up=IntPtr.Zero; SafeFileHandle h=null;
  try { US u=new US{Length=(ushort)(name.Length*2),MaximumLength=(ushort)(name.Length*2),Buffer=chars}; up=Marshal.AllocHGlobal(Marshal.SizeOf(typeof(US))); Marshal.StructureToPtr(u,up,false);
   OA a=new OA{Length=Marshal.SizeOf(typeof(OA)),RootDirectory=directory.DangerousGetHandle(),ObjectName=up,Attributes=0x40}; IOS status;
   int rc=NtCreateFile(out h,0x00120081,ref a,out status,IntPtr.Zero,0,0,1,0x200060,IntPtr.Zero,0);
   if(rc!=0 || status.Status!=IntPtr.Zero) throw new InvalidOperationException("open refused"); Check(h,false); SafeFileHandle result=h; h=null; return result;
  } finally { if(h!=null) h.Dispose(); if(up!=IntPtr.Zero) Marshal.FreeHGlobal(up); Marshal.FreeHGlobal(chars); }
 }
}
'@
